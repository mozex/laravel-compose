<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

use BackedEnum;
use Illuminate\Filesystem\Filesystem;
use Mozex\Compose\Exceptions\ComposeException;
use Stringable;

/**
 * Writes the env file Compose reads at `up` time. Values are quoted with
 * Compose's own rules so a space, a `#`, or a quote inside a secret never
 * truncates or corrupts the line.
 */
class EnvFile
{
    public function __construct(protected Filesystem $files) {}

    /**
     * @param  array<string, mixed>  $environment
     */
    public function write(string $path, array $environment, string $stack): void
    {
        $contents = static::render($environment, $stack);

        $this->files->ensureDirectoryExists(dirname($path));

        // Create the file empty and lock it down before a secret lands in it,
        // so it is never readable by others, not even between two calls.
        if (! $this->files->exists($path)) {
            $this->files->put($path, '');
        }

        $this->files->chmod($path, 0600);
        $this->files->put($path, $contents, true);
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    public static function render(array $environment, string $stack = 'stack'): string
    {
        $lines = [];

        foreach ($environment as $key => $value) {
            $key = (string) $key;

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw ComposeException::invalidEnvironmentKey($key, $stack);
            }

            $lines[] = $key.'='.static::quote(static::stringify($value, $key, $stack), $key, $stack);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    public static function quote(string $value, string $key = 'VALUE', string $stack = 'stack'): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw ComposeException::environmentValueHasNewline($key, $stack);
        }

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.\/:@+=,~%-]+$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '$$'], $value).'"';
    }

    protected static function stringify(mixed $value, string $key, string $stack): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => throw ComposeException::unsupportedEnvironmentValue($key, $stack, get_debug_type($value)),
        };
    }
}
