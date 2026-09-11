<?php

declare(strict_types=1);

use Mozex\Compose\DiscoveredStack;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Mozex\Compose\StackRegistry;
use Mozex\Compose\Tests\Fixtures\Modules\Gateway\Docker\GatewayStack;
use Mozex\Compose\Tests\Fixtures\Plain\Meilisearch\MeilisearchStack;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

it('discovers stacks under a plain parent directory, with or without a class', function (): void {
    config()->set('compose.discover', [fixturesPath('Plain')]);

    $stacks = app(StackRegistry::class)->all();

    expect(array_keys($stacks))->toBe(['mailpit', 'meilisearch'])
        ->and($stacks['meilisearch'])->toBeInstanceOf(MeilisearchStack::class)
        ->and($stacks['mailpit'])->toBeInstanceOf(DiscoveredStack::class)
        ->and($stacks['mailpit']->environment())->toBe([]);
});

it('discovers module-style directories through a glob and ignores ones without a compose file', function (): void {
    config()->set('compose.discover', [fixturesPath('Modules/*/Docker'), fixturesPath('Nowhere/*')]);

    $stacks = app(StackRegistry::class)->all();

    expect(array_keys($stacks))->toBe(['rdp-gateway'])
        ->and($stacks['rdp-gateway'])->toBeInstanceOf(GatewayStack::class);
});

it('registers configured classes and runtime stacks ahead of discovery without duplicating them', function (): void {
    config()->set('compose.stacks', [MeilisearchStack::class]);
    config()->set('compose.discover', [fixturesPath('Plain')]);

    $registry = app(StackRegistry::class);
    $registry->register(fakeStack(['name' => 'custom']));

    expect($registry->names())->toBe(['meilisearch', 'custom', 'mailpit'])
        ->and($registry->has('custom'))->toBeTrue()
        ->and($registry->find('missing'))->toBeNull()
        ->and($registry->get('custom')->name())->toBe('custom')
        ->and(fn () => $registry->get('missing'))->toThrow(ComposeException::class, 'No stack named [missing]');
});

it('refuses a configured entry that is not a stack class', function (): void {
    config()->set('compose.stacks', ['App\\Nope']);

    expect(fn () => app(StackRegistry::class)->all())->toThrow(ComposeException::class, '[App\\Nope] must be a class that extends');

    config()->set('compose.stacks', [stdClass::class]);

    expect(fn () => app(StackRegistry::class)->all())->toThrow(ComposeException::class, 'must be a class that extends');
});

it('refuses two stacks sharing a name and a stack with an invalid name', function (): void {
    $registry = app(StackRegistry::class);
    $registry->register(fakeStack(['name' => 'twin']))->register(fakeStack(['name' => 'twin']));

    expect(fn () => $registry->all())->toThrow(ComposeException::class, 'Two stacks are named [twin]');

    $registry = new StackRegistry(config(), app());
    $registry->register(fakeStack(['name' => 'Not Valid']));

    expect(fn () => $registry->all())->toThrow(ComposeException::class, '[Not Valid] from');
});

it('refuses a directory holding more than one stack class', function (): void {
    config()->set('compose.discover', [fixturesPath('Broken/Twin')]);

    expect(fn () => app(StackRegistry::class)->all())
        ->toThrow(ComposeException::class, 'more than one Stack class');
});

it('caches the resolved stacks until flushed or a stack is registered', function (): void {
    config()->set('compose.discover', [fixturesPath('Plain')]);
    $registry = app(StackRegistry::class);

    $first = $registry->all();
    config()->set('compose.discover', []);

    expect($registry->all())->toBe($first);

    $registry->flush();

    expect($registry->all())->toBe([]);

    $registry->register(fakeStack(['name' => 'late']));

    expect($registry->names())->toBe(['late']);
});

it('binds the registry as a singleton', function (): void {
    expect(app(StackRegistry::class))->toBe(app(StackRegistry::class))
        ->and(app(StackRegistry::class)->all())->toBeArray();
});

it('keeps stacks typed', function (): void {
    config()->set('compose.discover', [fixturesPath('Plain')]);

    foreach (app(StackRegistry::class)->all() as $stack) {
        expect($stack)->toBeInstanceOf(Stack::class);
    }
});
