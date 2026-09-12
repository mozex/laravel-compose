<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

use Closure;
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
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw ComposeException::missingComposeFile(dirname($path));
        }

        try {
            $data = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException $exception) {
            throw ComposeException::invalidComposeFile($path, $exception);
        }

        if (! is_array($data)) {
            throw ComposeException::invalidComposeFile($path);
        }

        /** @var array<string, mixed> $data */
        return new self($path, $data);
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
     * Every fixed `container_name`, resolved and sorted. A `${VAR}` in a name
     * is interpolated with the given values, as compose does at `up`, so the
     * sweep removes the container that actually exists. A name that does not
     * resolve to something Docker accepts is left out: nothing by that name
     * can be running. Services without a fixed name get a Compose-generated
     * one and need no sweep.
     *
     * @param  array<string, string>  $environment  Values used to resolve `${VAR}` in the names
     * @return list<string>
     */
    public function containerNames(array $environment = []): array
    {
        $names = [];

        foreach ($this->services() as $service) {
            $name = $service['container_name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $name = self::interpolate($name, $environment);

            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/', $name) === 1) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Variables the file consumes without a default, so they must be written
     * by the stack's environment(). Only string values of the parsed document
     * are scanned, so a commented-out line doesn't count. `${VAR:-x}`,
     * `${VAR-x}`, `${VAR:+x}`, and `${VAR+x}` carry their own fallback and are
     * not listed, but a variable used inside that fallback is; `$$` is
     * Compose's escaped dollar and is ignored.
     *
     * @return list<string>
     */
    public function requiredVariables(): array
    {
        $required = [];

        $this->eachString($this->data, function (string $value) use (&$required): void {
            $this->collectRequired($value, $required);
        });

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
                $port = $this->normalizePort($port);

                if ($this->publishesEverywhere($port, $environment)) {
                    $public[] = $name.': '.$this->describePort($port);
                }
            }
        }

        return $public;
    }

    /**
     * Every `${VAR...}` and `$VAR` reference in a string, outermost first,
     * with nested references inside a fallback left for the caller to scan.
     *
     * @return list<array{name: string, operator: string, argument: string}>
     */
    public static function variables(string $text): array
    {
        $found = [];
        $position = 0;

        while (($position = strpos($text, '$', $position)) !== false) {
            $next = $text[$position + 1] ?? '';

            if ($next === '$') {
                $position += 2;

                continue;
            }

            if ($next === '{') {
                $close = static::matchingBrace($text, $position + 1);

                // An unclosed brace is not a reference; keep scanning past it.
                if ($close === null) {
                    $position += 2;

                    continue;
                }

                $reference = static::parseReference(substr($text, $position + 2, $close - $position - 2));

                if ($reference !== null) {
                    $found[] = $reference;
                }

                $position = $close + 1;

                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($text, $position + 1), $match) === 1) {
                $found[] = ['name' => $match[0], 'operator' => '', 'argument' => ''];
                $position += 1 + strlen($match[0]);

                continue;
            }

            $position++;
        }

        return $found;
    }

    /**
     * Resolve `${VAR}`, `${VAR:-default}`, `${VAR-default}`, `${VAR:+alt}`,
     * `${VAR+alt}`, and `$VAR` the way Compose does, with the given values
     * standing in for the env file. Fallbacks may nest.
     *
     * @param  array<string, string>  $environment
     */
    public static function interpolate(string $value, array $environment): string
    {
        $result = '';
        $length = strlen($value);
        $position = 0;

        while ($position < $length) {
            $character = $value[$position];

            if ($character !== '$') {
                $result .= $character;
                $position++;

                continue;
            }

            $next = $value[$position + 1] ?? '';

            if ($next === '$') {
                $result .= '$';
                $position += 2;

                continue;
            }

            $close = $next === '{' ? static::matchingBrace($value, $position + 1) : null;

            if ($close !== null) {
                $reference = static::parseReference(substr($value, $position + 2, $close - $position - 2));

                if ($reference !== null) {
                    $result .= static::resolve($reference, $environment);
                    $position = $close + 1;

                    continue;
                }
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($value, $position + 1), $match) === 1) {
                $result .= $environment[$match[0]] ?? '';
                $position += 1 + strlen($match[0]);

                continue;
            }

            $result .= '$';
            $position++;
        }

        return $result;
    }

    /**
     * @param  array{name: string, operator: string, argument: string}  $reference
     * @param  array<string, string>  $environment
     */
    protected static function resolve(array $reference, array $environment): string
    {
        $name = $reference['name'];
        $set = array_key_exists($name, $environment);
        $filled = $set && $environment[$name] !== '';
        $fallback = fn (): string => static::interpolate($reference['argument'], $environment);

        return match ($reference['operator']) {
            '-' => $set ? $environment[$name] : $fallback(),
            ':-' => $filled ? $environment[$name] : $fallback(),
            '+' => $set ? $fallback() : '',
            ':+' => $filled ? $fallback() : '',
            default => $environment[$name] ?? '',
        };
    }

    /**
     * @return array{name: string, operator: string, argument: string}|null
     */
    protected static function parseReference(string $inner): ?array
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+?])(.*))?$/s', $inner, $match) !== 1) {
            return null;
        }

        return ['name' => $match[1], 'operator' => $match[2] ?? '', 'argument' => $match[3] ?? ''];
    }

    /**
     * Index of the `}` closing the `{` at the given position, or null.
     */
    protected static function matchingBrace(string $text, int $open): ?int
    {
        $depth = 0;
        $length = strlen($text);

        for ($index = $open; $index < $length; $index++) {
            if ($text[$index] === '{') {
                $depth++;
            }

            if ($text[$index] === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $required
     */
    protected function collectRequired(string $text, array &$required): void
    {
        foreach (static::variables($text) as $variable) {
            if (in_array($variable['operator'], ['-', ':-', '+', ':+'], true)) {
                $this->collectRequired($variable['argument'], $required);

                continue;
            }

            $required[] = $variable['name'];
        }
    }

    /**
     * @param  Closure(string): void  $callback
     */
    protected function eachString(mixed $value, Closure $callback): void
    {
        if (is_string($value)) {
            $callback($value);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->eachString($item, $callback);
        }
    }

    protected function bindMountSource(mixed $volume): ?string
    {
        if (is_array($volume)) {
            $type = $volume['type'] ?? null;
            $source = $volume['source'] ?? null;

            return $type === 'bind' && is_string($source) ? $source : null;
        }

        if (! is_string($volume)) {
            return null;
        }

        // A Windows source carries its own colon: C:\data:/data.
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $volume) === 1) {
            $parts = explode(':', $volume, 3);

            return count($parts) === 3 ? $parts[0].':'.$parts[1] : null;
        }

        if (! str_contains($volume, ':')) {
            return null;
        }

        $source = explode(':', $volume, 2)[0];

        return preg_match('/^(\.|\/|~|\$)/', $source) === 1 ? $source : null;
    }

    /**
     * YAML reads an unquoted `- 8000:8000` as a one-entry mapping; turn it
     * back into the string Compose would have seen.
     */
    protected function normalizePort(mixed $port): mixed
    {
        if (! is_array($port) || count($port) !== 1 || isset($port['target']) || isset($port['published'])) {
            return $port;
        }

        $key = array_key_first($port);
        $value = $port[$key];

        return is_scalar($value) ? $key.':'.$value : $port;
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
