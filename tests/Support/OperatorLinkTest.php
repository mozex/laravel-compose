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

it('replaces a stale real directory squatting the link path', function (): void {
    $squatter = linkPath();
    File::ensureDirectoryExists($squatter);
    File::put($squatter.'/stale.txt', 'left behind');
    $stack = fakeStack(['linkPath' => $squatter]);

    app(OperatorLink::class)->refresh($stack);

    clearstatcache();

    expect(File::exists($squatter.'/stale.txt'))->toBeFalse()
        ->and(File::exists($squatter.'/docker-compose.yml'))->toBeTrue()
        ->and(File::exists($stack->directory().'/docker-compose.yml'))->toBeTrue();
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

it('names the stack and the path when a squatter resists removal', function (): void {
    $squatter = linkPath();
    File::ensureDirectoryExists($squatter);
    File::put($squatter.'/stale.txt', 'left behind');
    File::partialMock()->shouldReceive('deleteDirectory')->andReturnFalse();

    expect(fn () => app(OperatorLink::class)->refresh(fakeStack(['name' => 'wedged', 'linkPath' => $squatter])))
        ->toThrow(ComposeException::class, "[wedged]: whatever sits at [{$squatter}] resisted removal");

    @unlink($squatter.'/stale.txt');
});
