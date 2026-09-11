<?php

declare(strict_types=1);

namespace Mozex\Compose\Exceptions;

use Mozex\Compose\Stack;
use RuntimeException;
use Throwable;

class ComposeException extends RuntimeException
{
    public static function invalidStackName(string $name, string $source): self
    {
        return new self(
            "The stack name [{$name}] from {$source} is not a valid Compose project name. "
            .'Use lowercase letters, digits, dashes, and underscores, starting with a letter or digit.',
        );
    }

    public static function duplicateStack(string $name, string $first, string $second): self
    {
        return new self(
            "Two stacks are named [{$name}]: {$first} and {$second}. Give one of them a different name() or compose `name:`.",
        );
    }

    public static function unknownStack(string $name): self
    {
        return new self("No stack named [{$name}] is registered. Run `compose:status` to list the known stacks.");
    }

    public static function invalidStackClass(string $class): self
    {
        return new self("[{$class}] must be a class that extends ".Stack::class.'.');
    }

    /**
     * @param  list<string>  $classes
     */
    public static function multipleStackClasses(string $directory, array $classes): self
    {
        return new self(
            "The directory [{$directory}] holds more than one Stack class (".implode(', ', $classes).'). '
            .'Keep one Stack class per compose file.',
        );
    }

    public static function missingComposeFile(string $directory): self
    {
        return new self("No compose file found in [{$directory}]. Expected docker-compose.yml, docker-compose.yaml, compose.yml, or compose.yaml.");
    }

    public static function invalidComposeFile(string $path, ?Throwable $previous = null): self
    {
        $reason = $previous === null ? 'it does not parse to a YAML mapping' : $previous->getMessage();

        return new self("The compose file [{$path}] could not be read: {$reason}", 0, $previous);
    }

    public static function invalidEnvironmentKey(string $key, string $stack): self
    {
        return new self(
            "The environment key [{$key}] on stack [{$stack}] is not a valid variable name. "
            .'Use letters, digits, and underscores, starting with a letter or underscore.',
        );
    }

    public static function environmentValueHasNewline(string $key, string $stack): self
    {
        return new self(
            "The environment value for [{$key}] on stack [{$stack}] contains a line break. "
            .'Compose env files are line-oriented, so the value cannot be written.',
        );
    }

    public static function unsupportedEnvironmentValue(string $key, string $stack, string $type): self
    {
        return new self(
            "The environment value for [{$key}] on stack [{$stack}] is a {$type}. "
            .'Only scalars, null, backed enums, and Stringable objects can be written to an env file.',
        );
    }

    public static function linkPathResisted(string $stack, string $path): self
    {
        return new self(
            "Cannot refresh the operator link for stack [{$stack}]: whatever sits at [{$path}] resisted removal. "
            .'An old link or an empty directory is replaced; a directory with content is left alone. '
            .'Move it away, or make the path writable by the deploy user.',
        );
    }

    public static function unresolvableNamespace(string $directory): self
    {
        return new self(
            "No PSR-4 autoload mapping in composer.json covers [{$directory}], so a Stack class there would never load. "
            .'Pick a directory under an autoloaded namespace or add a mapping.',
        );
    }

    public static function stackDirectoryExists(string $directory): self
    {
        return new self("The stack directory [{$directory}] already exists.");
    }
}
