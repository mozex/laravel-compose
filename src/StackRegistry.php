<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Support\ComposeFile;
use ReflectionClass;
use SplFileInfo;

/**
 * Every stack the app owns: the classes listed in config, the ones registered
 * at runtime, and the ones found by scanning the configured directories.
 */
class StackRegistry
{
    /**
     * @var array<string, Stack>|null
     */
    protected ?array $stacks = null;

    /**
     * @var list<Stack|class-string<Stack>>
     */
    protected array $registered = [];

    /**
     * Classes declared by files in a discovered stack directory that could
     * not be loaded, keyed by directory. The doctor reports them.
     *
     * @var array<string, list<string>>
     */
    protected array $unloadable = [];

    public function __construct(
        protected Repository $config,
        protected Container $container,
    ) {}

    /**
     * @param  Stack|class-string<Stack>  $stack
     */
    public function register(Stack|string $stack): static
    {
        $this->registered[] = $stack;
        $this->stacks = null;

        return $this;
    }

    /**
     * @return array<string, Stack> Keyed by stack name
     */
    public function all(): array
    {
        return $this->stacks ??= $this->resolve();
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    public function find(string $name): ?Stack
    {
        return $this->all()[$name] ?? null;
    }

    public function get(string $name): Stack
    {
        return $this->find($name) ?? throw ComposeException::unknownStack($name);
    }

    public function flush(): void
    {
        $this->stacks = null;
        $this->unloadable = [];
    }

    /**
     * Class names found in discovered stack directories that PHP could not
     * autoload (a namespace that doesn't match the PSR-4 map, a stale
     * authoritative classmap). Such a directory is treated as class-less,
     * which is rarely what was meant.
     *
     * @return array<string, list<string>> Keyed by stack directory
     */
    public function unloadableClasses(): array
    {
        $this->all();

        return $this->unloadable;
    }

    /**
     * @return array<string, Stack>
     */
    protected function resolve(): array
    {
        $stacks = [];
        $this->unloadable = [];
        $configured = $this->config->get('compose.stacks', []);

        foreach ([...(is_array($configured) ? $configured : []), ...$this->registered] as $entry) {
            $this->add($stacks, $this->instantiate($entry));
        }

        foreach ($this->discover() as $stack) {
            if ($this->alreadyPresent($stacks, $stack)) {
                continue;
            }

            $this->add($stacks, $stack);
        }

        return $stacks;
    }

    /**
     * @param  array<string, Stack>  $stacks
     */
    protected function add(array &$stacks, Stack $stack): void
    {
        $name = $stack->name();

        if (! Stack::isValidName($name)) {
            throw ComposeException::invalidStackName($name, $this->describe($stack));
        }

        if (isset($stacks[$name])) {
            throw ComposeException::duplicateStack($name, $this->describe($stacks[$name]), $this->describe($stack));
        }

        $stacks[$name] = $stack;
    }

    /**
     * @param  array<string, Stack>  $stacks
     */
    protected function alreadyPresent(array $stacks, Stack $candidate): bool
    {
        foreach ($stacks as $stack) {
            if ($this->comparable($stack->directory()) === $this->comparable($candidate->directory())) {
                return true;
            }
        }

        return false;
    }

    protected function instantiate(mixed $entry): Stack
    {
        if ($entry instanceof Stack) {
            return $entry;
        }

        if (! is_string($entry) || ! class_exists($entry) || ! is_subclass_of($entry, Stack::class)) {
            throw ComposeException::invalidStackClass(is_string($entry) ? $entry : get_debug_type($entry));
        }

        return $this->container->make($entry);
    }

    /**
     * @return list<Stack>
     */
    protected function discover(): array
    {
        $patterns = $this->config->get('compose.discover', []);
        $stacks = [];

        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            foreach ($this->matchDirectories($pattern) as $directory) {
                foreach ($this->stackDirectories($directory) as $stackDirectory) {
                    $stacks[] = $this->stackFromDirectory($stackDirectory);
                }
            }
        }

        return $stacks;
    }

    /**
     * @return list<string>
     */
    protected function matchDirectories(string $pattern): array
    {
        $matches = glob(str_replace('\\', '/', $pattern), GLOB_ONLYDIR);

        if ($matches === false) {
            return [];
        }

        sort($matches);

        return $matches;
    }

    /**
     * A matched directory holding a compose file is itself a stack. Otherwise
     * it is a parent, and each child directory holding a compose file is one.
     *
     * @return list<string>
     */
    protected function stackDirectories(string $directory): array
    {
        if (ComposeFile::find($directory) !== null) {
            return [$directory];
        }

        $children = glob(rtrim($directory, '/\\').'/*', GLOB_ONLYDIR);

        if ($children === false) {
            return [];
        }

        sort($children);

        return array_values(array_filter($children, fn (string $child): bool => ComposeFile::find($child) !== null));
    }

    protected function stackFromDirectory(string $directory): Stack
    {
        $classes = $this->stackClassesIn($directory);

        if (count($classes) > 1) {
            throw ComposeException::multipleStackClasses($directory, $classes);
        }

        if ($classes === []) {
            return new DiscoveredStack($directory);
        }

        return $this->container->make($classes[0]);
    }

    /**
     * Concrete Stack subclasses declared by the PHP files directly in the
     * directory. Subdirectories are not scanned: a stack can bind-mount a
     * whole PHP tree, and nothing in there is a Stack class beside the
     * compose file.
     *
     * @return list<class-string<Stack>>
     */
    protected function stackClassesIn(string $directory): array
    {
        $classes = [];
        $files = array_map(fn (string $path): SplFileInfo => new SplFileInfo($path), glob(rtrim($directory, '/\\').'/*.php') ?: []);

        foreach (array_keys(ClassMapGenerator::createMap($files)) as $class) {
            if (! class_exists($class)) {
                $this->unloadable[$this->comparable($directory)][] = $class;

                continue;
            }

            if (! is_subclass_of($class, Stack::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    protected function describe(Stack $stack): string
    {
        return $stack::class.' in '.$stack->directory();
    }

    protected function comparable(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
