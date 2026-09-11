<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

use Mozex\Compose\Exceptions\ComposeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed compose file. Everything the package derives from the YAML (the
 * project name, the fixed container names, the variables the file consumes,
 * the ports it publishes) comes through here so no other class parses YAML.
 */
class ComposeFile
{
    /**
     * The file names Compose itself looks for, in its own lookup order.
     */
    public const CANDIDATES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected string $path,
        protected array $data,
        protected string $raw,
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw ComposeException::missingComposeFile(dirname($path));
        }

        $raw = (string) file_get_contents($path);

        try {
            $data = Yaml::parse($raw);
        } catch (ParseException $exception) {
            throw ComposeException::invalidComposeFile($path, $exception);
        }

        if (! is_array($data)) {
            throw ComposeException::invalidComposeFile($path);
        }

        /** @var array<string, mixed> $data */
        return new self($path, $data, $raw);
    }

    /**
     * The compose file inside a directory, or null when the directory holds none.
     */
    public static function find(string $directory): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    /**
     * The top-level `name:` when the file declares one.
     */
    public function name(): ?string
    {
        $name = $this->data['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function services(): array
    {
        $services = $this->data['services'] ?? [];

        if (! is_array($services)) {
            return [];
        }

        $result = [];

        foreach ($services as $name => $service) {
            $result[(string) $name] = is_array($service) ? $service : [];
        }

        return $result;
    }

    /**
     * Every fixed `container_name`, sorted. Services without one get a
     * Compose-generated name and need no sweep.
     *
     * @return list<string>
     */
    public function containerNames(): array
    {
        $names = [];

        foreach ($this->services() as $service) {
            $name = $service['container_name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Variables the file consumes without a default, so they must be written
     * by the stack's environment(). `${VAR:-x}`, `${VAR-x}`, `${VAR:+x}`, and
     * `${VAR+x}` carry their own fallback and are not listed; `$$` is Compose's
     * escaped dollar and is ignored.
     *
     * @return list<string>
     */
    public function requiredVariables(): array
    {
        preg_match_all(
            '/(?<!\$)\$(?:\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+?])[^}]*)?\}|([A-Za-z_][A-Za-z0-9_]*))/',
            str_replace('$$', '', $this->raw),
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        $required = [];

        foreach ($matches as $match) {
            $name = $match[1] ?? $match[3];

            if ($name === null || in_array($match[2], ['-', ':-', '+', ':+'], true)) {
                continue;
            }

            $required[] = $name;
        }

        $required = array_values(array_unique($required));
        sort($required);

        return $required;
    }

    /**
     * Every profile any service opts into.
     *
     * @return list<string>
     */
    public function profiles(): array
    {
        $profiles = [];

        foreach ($this->services() as $service) {
            $declared = $service['profiles'] ?? [];

            foreach (is_array($declared) ? $declared : [$declared] as $profile) {
                if (is_string($profile) && $profile !== '') {
                    $profiles[] = $profile;
                }
            }
        }

        $profiles = array_values(array_unique($profiles));
        sort($profiles);

        return $profiles;
    }

    public function hasBuildSteps(): bool
    {
        foreach ($this->services() as $service) {
            if (array_key_exists('build', $service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host-side sources of bind mounts, the paths that only exist on the
     * machine the compose file lives on.
     *
     * @return list<string>
     */
    public function bindMounts(): array
    {
        $sources = [];

        foreach ($this->services() as $service) {
            $volumes = $service['volumes'] ?? [];

            foreach (is_array($volumes) ? $volumes : [] as $volume) {
                $source = $this->bindMountSource($volume);

                if ($source !== null) {
                    $sources[] = $source;
                }
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * Port publishes that listen on every interface. A publish without a host
     * address is public on any machine whose firewall Docker bypasses, which is
     * most of them. Entries read `service: published`.
     *
     * @param  array<string, string>  $environment  Values used to resolve `${VAR}` in the port entries
     * @return list<string>
     */
    public function publicPublishes(array $environment = []): array
    {
        $public = [];

        foreach ($this->services() as $name => $service) {
            $ports = $service['ports'] ?? [];

            foreach (is_array($ports) ? $ports : [] as $port) {
                if ($this->publishesEverywhere($port, $environment)) {
                    $public[] = $name.': '.$this->describePort($port);
                }
            }
        }

        return $public;
    }

    /**
     * Resolve `${VAR}`, `${VAR:-default}`, `${VAR-default}`, and `$VAR` the way
     * Compose does, with the given values standing in for the env file.
     *
     * @param  array<string, string>  $environment
     */
    public static function interpolate(string $value, array $environment): string
    {
        $result = preg_replace_callback(
            '/(?<!\$)\$(?:\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+?])([^}]*))?\}|([A-Za-z_][A-Za-z0-9_]*))/',
            function (array $match) use ($environment): string {
                $name = $match[1] ?? $match[4] ?? '';
                $argument = $match[3] ?? '';
                $set = array_key_exists($name, $environment);
                $filled = $set && $environment[$name] !== '';

                return match ($match[2] ?? '') {
                    '-' => $set ? $environment[$name] : $argument,
                    ':-' => $filled ? $environment[$name] : $argument,
                    '+' => $set ? $argument : '',
                    ':+' => $filled ? $argument : '',
                    default => $environment[$name] ?? '',
                };
            },
            $value,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );

        return str_replace('$$', '$', (string) $result);
    }

    protected function bindMountSource(mixed $volume): ?string
    {
        if (is_array($volume)) {
            $type = $volume['type'] ?? null;
            $source = $volume['source'] ?? null;

            return $type === 'bind' && is_string($source) ? $source : null;
        }

        if (! is_string($volume) || ! str_contains($volume, ':')) {
            return null;
        }

        $source = explode(':', $volume, 2)[0];

        if (preg_match('/^(\.|\/|~|[A-Za-z]:[\\\\\/]|\$)/', $source) !== 1) {
            return null;
        }

        return $source;
    }

    /**
     * @param  array<string, string>  $environment
     */
    protected function publishesEverywhere(mixed $port, array $environment): bool
    {
        if (is_array($port)) {
            $hostIp = $port['host_ip'] ?? null;

            if (! is_string($hostIp) || $hostIp === '') {
                return true;
            }

            return $this->isWildcardAddress(self::interpolate($hostIp, $environment));
        }

        if (is_int($port)) {
            return true;
        }

        if (! is_string($port)) {
            return false;
        }

        $entry = self::interpolate(explode('/', $port, 2)[0], $environment);
        $segments = explode(':', $entry);

        if (count($segments) < 3) {
            return true;
        }

        $hostIp = implode(':', array_slice($segments, 0, -2));

        return $this->isWildcardAddress($hostIp);
    }

    protected function isWildcardAddress(string $address): bool
    {
        return in_array(trim($address, '[]'), ['', '0.0.0.0', '::'], true);
    }

    protected function describePort(mixed $port): string
    {
        if (is_array($port)) {
            $published = $port['published'] ?? '?';
            $target = $port['target'] ?? '?';

            return (is_scalar($published) ? (string) $published : '?').':'.(is_scalar($target) ? (string) $target : '?');
        }

        return is_scalar($port) ? (string) $port : '?';
    }
}
