<?php

declare(strict_types=1);

namespace Mozex\Compose\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Mozex\Compose\Support\NamespaceResolver;

class MakeStackCommand extends Command
{
    protected $signature = 'compose:make
        {name : The stack name, for example meilisearch}
        {--path= : Parent directory for the new stack, absolute or relative to the app root (defaults to the first discovery directory)}';

    protected $description = 'Scaffold a Docker Compose stack: a compose file and the Stack class beside it';

    public function handle(Repository $config, Filesystem $files, NamespaceResolver $namespaces): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Stack::normalizeName($name);
        $class = Str::studly($slug);

        if (! Stack::isValidName($slug) || preg_match('/^[A-Za-z]/', $class) !== 1) {
            $this->components->error(ComposeException::unusableStackName($name)->getMessage());

            return self::FAILURE;
        }

        $directory = rtrim($this->parentDirectory($config), '/\\').DIRECTORY_SEPARATOR.$class;

        if ($files->exists($directory)) {
            $this->components->error(ComposeException::stackDirectoryExists($directory)->getMessage());

            return self::FAILURE;
        }

        try {
            $namespace = $namespaces->resolve($directory);
        } catch (ComposeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class.'Stack',
            '{{ slug }}' => $slug,
            '{{ upper }}' => strtoupper(str_replace('-', '_', $slug)),
            '{{ project }}' => $this->appSlug($config).'-'.$slug,
        ];

        $files->ensureDirectoryExists($directory);

        foreach (['stack.php.stub' => $class.'Stack.php', 'docker-compose.yml.stub' => 'docker-compose.yml', 'gitignore.stub' => '.gitignore'] as $stub => $target) {
            $files->put(
                $directory.DIRECTORY_SEPARATOR.$target,
                strtr((string) $files->get(__DIR__.'/../../resources/stubs/'.$stub), $replacements),
            );
        }

        $this->components->info("Stack [{$replacements['{{ project }}']}] scaffolded in [{$directory}].");
        $this->components->bulletList([
            'Edit docker-compose.yml: the image, ports, volumes, and healthcheck.',
            "Fill environment() in {$class}Stack.php with the values the compose file consumes.",
            'Run `php artisan compose:doctor` to check the result, then `php artisan compose:redeploy`.',
        ]);

        return self::SUCCESS;
    }

    protected function parentDirectory(Repository $config): string
    {
        /** @var string|null $path */
        $path = $this->option('path');

        if ($path !== null && trim($path) !== '') {
            return $this->absolute(trim($path));
        }

        $discover = $config->get('compose.discover', []);

        foreach (is_array($discover) ? $discover : [] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && ! str_contains($candidate, '*')) {
                return $this->absolute($candidate);
            }
        }

        return $this->laravel->basePath('app'.DIRECTORY_SEPARATOR.'Docker');
    }

    protected function absolute(string $path): string
    {
        if (preg_match('/^([A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1) {
            return $path;
        }

        return $this->laravel->basePath($path);
    }

    /**
     * Container and project names are global on a Docker host. Prefixing them
     * with the app keeps two apps that both scaffold a `meilisearch` stack from
     * sweeping each other's containers.
     */
    protected function appSlug(Repository $config): string
    {
        $name = $config->get('app.name');
        $slug = Stack::normalizeName(is_string($name) ? $name : '');

        return $slug === '' ? 'app' : $slug;
    }
}
