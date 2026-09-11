<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mozex\Compose\Stack;
use Mozex\Compose\Tests\TestCase;

uses(TestCase::class)->afterEach(function (): void {
    removeTemporaryDirectories();
})->in(__DIR__);

function fixturesPath(string $path = ''): string
{
    return str_replace('\\', '/', __DIR__.'/Fixtures'.($path === '' ? '' : '/'.ltrim($path, '/')));
}

/**
 * A fresh directory under the system temp directory, removed after the test.
 */
function temporaryDirectory(): string
{
    $directory = str_replace('\\', '/', sys_get_temp_dir()).'/laravel-compose-'.getmypid().'-'.uniqid();

    File::ensureDirectoryExists($directory);

    $directories = &temporaryDirectories();
    $directories[] = $directory;

    return $directory;
}

/**
 * @return list<string>
 */
function &temporaryDirectories(): array
{
    static $directories = [];

    return $directories;
}

function removeTemporaryDirectories(): void
{
    $directories = &temporaryDirectories();

    foreach (array_reverse($directories) as $directory) {
        removeTree($directory);
    }

    $directories = [];
}

/**
 * Deletes a tree without ever following a link or a junction into another
 * directory: a link is unlinked where it stands, and only real directories
 * are recursed into.
 */
function removeTree(string $path): void
{
    clearstatcache(true, $path);

    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    $target = @readlink($path);
    $isLink = is_link($path)
        || ($target !== false && strtolower(str_replace('\\', '/', rtrim($target, '/\\'))) !== strtolower(str_replace('\\', '/', rtrim($path, '/\\'))));

    if ($isLink || ! is_dir($path)) {
        @rmdir($path) || @unlink($path);

        return;
    }

    foreach (array_diff((array) scandir($path), ['.', '..']) as $child) {
        removeTree($path.'/'.$child);
    }

    @rmdir($path);
}

/**
 * A stack whose every knob comes from the overrides, living in a temporary
 * directory with a compose file. Tests drive scenarios through it without
 * touching the fixture directories.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeStack(array $overrides = []): Stack
{
    $overrides['directory'] ??= temporaryDirectory();
    $overrides['name'] ??= 'fake';

    if (! isset($overrides['compose'])) {
        $overrides['compose'] = "name: {$overrides['name']}\nservices:\n    app:\n        image: alpine:3\n        container_name: {$overrides['name']}-app\n";
    }

    File::put($overrides['directory'].'/docker-compose.yml', $overrides['compose']);

    return new class($overrides) extends Stack
    {
        /**
         * @param  array<string, mixed>  $overrides
         */
        public function __construct(protected array $overrides) {}

        public function name(): string
        {
            return $this->overrides['name'];
        }

        public function directory(): string
        {
            return $this->overrides['directory'];
        }

        public function environment(): array
        {
            return $this->overrides['environment'] ?? ['FAKE_KEY' => 'value'];
        }

        public function enabled(): bool
        {
            return $this->overrides['enabled'] ?? true;
        }

        public function containerNames(): array
        {
            return $this->overrides['containers'] ?? parent::containerNames();
        }

        public function profiles(): array
        {
            return $this->overrides['profiles'] ?? [];
        }

        public function build(): bool
        {
            return $this->overrides['build'] ?? false;
        }

        public function pull(): bool
        {
            return $this->overrides['pull'] ?? true;
        }

        public function wait(): ?int
        {
            return $this->overrides['wait'] ?? null;
        }

        public function timeout(): ?int
        {
            return $this->overrides['timeout'] ?? null;
        }

        public function linkPath(): ?string
        {
            return $this->overrides['linkPath'] ?? null;
        }

        public function host(): ?string
        {
            return $this->overrides['host'] ?? null;
        }

        public function context(): ?string
        {
            return $this->overrides['context'] ?? null;
        }
    };
}

function dockerComposeAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    $output = [];
    $exitCode = 1;

    @exec('docker compose version 2>&1', $output, $exitCode);

    return $available = $exitCode === 0;
}
