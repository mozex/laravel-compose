<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Compose;
use Mozex\Compose\Docker;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Facades\Compose as ComposeFacade;
use Mozex\Compose\Tests\Fixtures\Plain\Meilisearch\MeilisearchStack;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

it('exposes the registry through the manager and the facade', function (): void {
    $stack = fakeStack(['name' => 'one']);
    ComposeFacade::register($stack);

    expect(ComposeFacade::stacks())->toBe(['one' => $stack])
        ->and(ComposeFacade::stack('one'))->toBe($stack)
        ->and(ComposeFacade::has('one'))->toBeTrue()
        ->and(ComposeFacade::has('two'))->toBeFalse()
        ->and(ComposeFacade::enabled())->toBeTrue()
        ->and(ComposeFacade::docker())->toBeInstanceOf(Docker::class)
        ->and(app(Compose::class))->toBe(app(Compose::class))
        ->and(fn () => ComposeFacade::stack('two'))->toThrow(ComposeException::class, '[two]');

    config()->set('compose.enabled', false);
    expect(ComposeFacade::enabled())->toBeFalse();
});

it('redeploys every stack or only the named one', function (): void {
    Process::fake();
    $first = fakeStack(['name' => 'first']);
    $second = fakeStack(['name' => 'second', 'enabled' => false]);
    ComposeFacade::register($first)->register($second);

    expect(ComposeFacade::redeploy())->toBe(['first' => RedeployResult::Redeployed, 'second' => RedeployResult::Skipped]);

    File::delete($first->directory().'/.env');

    expect(ComposeFacade::redeploy('first'))->toBe(['first' => RedeployResult::Redeployed])
        ->and(File::exists($first->directory().'/.env'))->toBeTrue()
        ->and(fn () => ComposeFacade::redeploy('nope'))->toThrow(ComposeException::class, '[nope]');
});

it('accepts a stack class name at registration', function (): void {
    ComposeFacade::register(MeilisearchStack::class);

    expect(ComposeFacade::stack('meilisearch'))->toBeInstanceOf(MeilisearchStack::class);
});
