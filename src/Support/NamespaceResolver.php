<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

use Composer\Autoload\ClassLoader;
use Mozex\Compose\Exceptions\ComposeException;
use ReflectionClass;

/**
 * Turns a directory into the namespace Composer would autoload it under, by
 * reading the PSR-4 map Composer generated. Lets the scaffolder work in any
 * layout: app/Docker, Modules/*, src, whatever composer.json maps.
 */
class NamespaceResolver
{
    /**
     * @param  array<string, list<string>>  $psr4  Namespace prefix => directories
     */
    public function __construct(protected array $psr4) {}

    public static function fromComposer(): self
    {
        $file = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName()).'/autoload_psr4.php';
        $map = is_file($file) ? require $file : [];

        if (! is_array($map)) {
            $map = [];
        }

        /** @var array<string, list<string>> $map */
        return new self($map);
    }

    public function resolve(string $directory): string
    {
        $target = static::normalize($directory);
        $bestPrefix = null;
        $bestRoot = '';

        foreach ($this->psr4 as $prefix => $roots) {
            foreach ($roots as $root) {
                $root = static::normalize($root);

                if (! $this->contains($root, $target) || strlen($root) < strlen($bestRoot)) {
                    continue;
                }

                $bestPrefix = $prefix;
                $bestRoot = $root;
            }
        }

        if ($bestPrefix === null) {
            throw ComposeException::unresolvableNamespace($directory);
        }

        $relative = trim(substr($target, strlen($bestRoot)), '/');
        $namespace = rtrim($bestPrefix, '\\');

        return $relative === '' ? $namespace : $namespace.'\\'.str_replace('/', '\\', $relative);
    }

    /**
     * Forward slashes, no `.` or `..` segments, no trailing slash. Case is
     * kept, because it becomes part of the namespace.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^([A-Za-z]:)?\//', $path, $match) === 1) {
            $prefix = ($match[1] ?? '').'/';
            $path = substr($path, strlen($prefix));
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }

    /**
     * Windows paths compare case-insensitively; everything else is exact.
     */
    protected function contains(string $root, string $target): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $target = strtolower($target);
        }

        return $target === $root || str_starts_with($target, $root.'/');
    }
}
