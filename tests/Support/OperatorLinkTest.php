<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Support\OperatorLink;

afterEach(function (): void {
    $paths = &linkPaths();

    foreach ($paths as $link) {
        @rmdir($link) || @unlink($link);
    }

    $paths = [];
});

/**
 * @return list<string>
 */
function &linkPaths(): array
{
    static $paths = [];

    return $paths;
}

function linkPath(): string
{
    $path = str_replace('\\', '/', sys_get_temp_dir()).'/laravel-compose-link-'.getmypid().'-'.uniqid();

    $paths = &linkPaths();
    $paths[] = $path;

    return $path;
}

it('derives the link path from the configured directory and the stack name', function (): void {
    $link = app(OperatorLink::class);

    expect($link->pathFor(fakeStack(['name' => 'meili'])))->toBeNull();

    config()->set('compose.link_directory', '/home/deploy/containers/');

    expect($link->pathFor(fakeStack(['name' => 'meili'])))->toBe('/home/deploy/containers/meili')
        ->and($link->pathFor(fakeStack(['linkPath' => '/srv/stacks/custom/'])))->toBe('/srv/stacks/custom')
        ->and($link->pathFor(fakeStack(['linkPath' => ''])))->toBeNull()
        ->and($link->pathFor(fakeStack(['linkPath' => '  '])))->toBeNull();
});

it('does nothing when no link is configured', function (): void {
    expect(app(OperatorLink::class)->refresh(fakeStack()))->toBeNull();
});

it('links the stack directory and leaves a correct link alone on the next run', function (): void {
    $stack = fakeStack(['linkPath' => linkPath().'/']);
    $link = app(OperatorLink::class);

    $first = $link->refresh($stack);
    $second = $link->refresh($stack);

    clearstatcache();

    expect($first)->toBe($second)
        ->and(File::exists($first.'/docker-compose.yml'))->toBeTrue()
        ->and(File::exists($stack->directory().'/docker-compose.yml'))->toBeTrue()
        ->and(str_replace('\\', '/', (string) @readlink($first)))->toBe($stack->directory());
});

it('replaces an empty directory squatting the link path', function (): void {
    // Hosting panels pre-create the stack directory under their containers
    // directory; an empty one gives way to the link.
    $squatter = linkPath();
    File::ensureDirectoryExists($squatter);
    $stack = fakeStack(['linkPath' => $squatter]);

    app(OperatorLink::class)->refresh($stack);

    clearstatcache();

    expect(str_replace('\\', '/', (string) @readlink($squatter)))->toBe($stack->directory())
        ->and(File::exists($squatter.'/docker-compose.yml'))->toBeTrue();
});

it('refuses a directory with content instead of deleting it', function (): void {
    $squatter = linkPath();
    File::ensureDirectoryExists($squatter);
    File::put($squatter.'/precious.txt', 'do not delete');
    $stack = fakeStack(['name' => 'careful', 'linkPath' => $squatter]);

    expect(fn () => app(OperatorLink::class)->refresh($stack))
        ->toThrow(ComposeException::class, "[careful]: whatever sits at [{$squatter}] resisted removal")
        ->and(File::get($squatter.'/precious.txt'))->toBe('do not delete');

    @unlink($squatter.'/precious.txt');
});

it('repoints a link left over from an older release', function (): void {
    $older = temporaryDirectory();
    File::put($older.'/docker-compose.yml', 'name: older');
    $path = linkPath();
    File::link($older, $path);
    $stack = fakeStack(['linkPath' => $path]);

    app(OperatorLink::class)->refresh($stack);

    clearstatcache();

    expect(str_replace('\\', '/', (string) @readlink($path)))->toBe($stack->directory())
        ->and(File::exists($older.'/docker-compose.yml'))->toBeTrue();
});

it('refuses a plain file at the link path', function (): void {
    $file = linkPath();
    File::put($file, 'operator notes');

    expect(fn () => app(OperatorLink::class)->refresh(fakeStack(['name' => 'noted', 'linkPath' => $file])))
        ->toThrow(ComposeException::class, "[noted]: whatever sits at [{$file}] resisted removal")
        ->and(File::get($file))->toBe('operator notes');
});

it('explains what is replaced and what is left alone', function (): void {
    $squatter = linkPath();
    File::ensureDirectoryExists($squatter);
    File::put($squatter.'/stale.txt', 'left behind');

    expect(fn () => app(OperatorLink::class)->refresh(fakeStack(['name' => 'wedged', 'linkPath' => $squatter])))
        ->toThrow(ComposeException::class, 'a directory with content is left alone');

    @unlink($squatter.'/stale.txt');
});
