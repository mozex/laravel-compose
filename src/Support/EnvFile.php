<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

use BackedEnum;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Stringable;

/**
 * Writes the env file Compose reads at `up` time. Values are quoted with
 * Compose's own rules so a space, a `#`, or a quote inside a secret never
 * truncates or corrupts the line.
 */
class EnvFile
{
    public function __construct(
        protected Filesystem $files,
        protected Repository $config,
    ) {}

    /**
     * The configured env file name, `.env` unless `env_file` says otherwise.
     */
    public function name(): string
    {
        $file = $this->config->get('compose.env_file', '.env');

        return is_string($file) && trim($file) !== '' ? trim($file) : '.env';
    }

    /**
     * The env file inside the stack directory.
     */
    public function pathFor(Stack $stack): string
    {
        return $stack->directory().DIRECTORY_SEPARATOR.$this->name();
    }

    /**
     * Whether the stack's env file was written by hand: nothing to write from
     * environment(), and a non-empty file already in place. A redeploy leaves
     * such a file alone, and the doctor reads it instead.
     */
    public function isHandWritten(Stack $stack): bool
    {
        if ($stack->environment() !== []) {
            return false;
        }

        $path = $this->pathFor($stack);

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * The values compose reads back for this stack: environment() as the env
     * file renders it, or the hand-written file when there is nothing to write.
     *
     * @return array<string, string>
     */
    public function valuesFor(Stack $stack): array
    {
        if ($this->isHandWritten($stack)) {
            return static::parse((string) file_get_contents($this->pathFor($stack)));
        }

        return static::values($stack->environment());
    }

    /**
     * Every value as the string the env file would carry, for resolving
     * `${VAR}` the way compose will. Nothing throws here: render() is where
     * an unwritable value is refused.
     *
     * @param  array<string, mixed>  $environment
     * @return array<string, string>
     */
    public static function values(array $environment): array
    {
        $strings = [];

        foreach ($environment as $key => $value) {
            $strings[(string) $key] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                $value instanceof BackedEnum => (string) $value->value,
                is_scalar($value), $value instanceof Stringable => (string) $value,
                default => '',
            };
        }

        return $strings;
    }

    /**
     * The variable names an env file defines, in file order.
     *
     * @return list<string>
     */
    public static function keys(string $contents): array
    {
        return array_keys(static::parse($contents));
    }

    /**
     * The variables an env file defines, read with the same quoting rules
     * quote() writes: a single-quoted value is literal, a double-quoted one
     * unescapes `\"`, `\\`, and `$$`, and an unquoted one stops at ` #`.
     * Comments, blank lines, and an `export` prefix are skipped. A key
     * defined twice keeps its last value, as Compose does.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $values[$match[1]] = static::unquote(trim($match[2]));
        }

        return $values;
    }

    /**
     * A quoted value ends at its closing quote; whatever follows (a comment,
     * usually) is dropped, as Compose does.
     */
    protected static function unquote(string $value): string
    {
        if (preg_match("/^'([^']*)'/", $value, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $match) === 1) {
            return str_replace(['\\"', '\\\\', '$$'], ['"', '\\', '$'], $match[1]);
        }

        return trim((string) preg_replace('/\s#.*$/', '', $value));
    }

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

        // Compose reads a backslash before the closing quote as an escaped
        // quote, single quotes included, so a value ending in one has to be
        // double-quoted or the file fails with "unterminated quoted value".
        if (! str_contains($value, "'") && ! str_ends_with($value, '\\')) {
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
