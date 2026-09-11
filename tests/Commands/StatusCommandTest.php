<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Mozex\Compose\Facades\Compose;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

it('tables every container with its state, health, and ports', function (): void {
    Process::fake([
        '*' => Process::result(implode("\n", [
            '{"Name":"meili","Service":"meilisearch","State":"running","Health":"healthy","Status":"Up","ExitCode":0,"Publishers":[{"URL":"127.0.0.1","TargetPort":7700,"PublishedPort":7700,"Protocol":"tcp"}]}',
            '{"Name":"meili-init","Service":"init","State":"exited","Health":"","Status":"Exited (0)","ExitCode":0,"Publishers":[]}',
        ])),
    ]);
    Compose::register(fakeStack(['name' => 'meili']));

    artisan('compose:status')
        ->expectsTable(['Stack', 'Service', 'Container', 'State', 'Health', 'Ports'], [
            ['meili', 'meilisearch', 'meili', 'running', 'healthy', '127.0.0.1:7700->7700/tcp'],
            ['meili', 'init', 'meili-init', 'exited', '-', '-'],
        ])
        ->assertSuccessful();
});

it('fails when an enabled stack has no containers but passes for a disabled one', function (): void {
    Process::fake(['*' => Process::result('')]);
    Compose::register(fakeStack(['name' => 'missing']))->register(fakeStack(['name' => 'off', 'enabled' => false]));

    artisan('compose:status', ['stack' => 'missing'])
        ->expectsTable(['Stack', 'Service', 'Container', 'State', 'Health', 'Ports'], [['missing', '-', '-', 'not created', '-', '-']])
        ->assertFailed();

    artisan('compose:status', ['stack' => 'off'])
        ->expectsTable(['Stack', 'Service', 'Container', 'State', 'Health', 'Ports'], [['off', '-', '-', 'disabled', '-', '-']])
        ->assertSuccessful();
});

it('does not ask docker about disabled stacks in the overview', function (): void {
    Process::fake(['*' => Process::result('')]);
    Compose::register(fakeStack(['name' => 'off', 'enabled' => false]));

    artisan('compose:status')
        ->expectsTable(['Stack', 'Service', 'Container', 'State', 'Health', 'Ports'], [['off', '-', '-', 'disabled', '-', '-']])
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('fails when a container crashed', function (): void {
    Process::fake(['*' => Process::result('{"Name":"x","Service":"app","State":"exited","Health":"","Status":"Exited (1)","ExitCode":1,"Publishers":[]}')]);
    Compose::register(fakeStack(['name' => 'crashed']));

    artisan('compose:status')->assertFailed();
});

it('reports an unknown stack and an empty registry', function (): void {
    Process::fake();

    artisan('compose:status')->expectsOutputToContain('No stacks are registered.')->assertSuccessful();
    artisan('compose:status', ['stack' => 'ghost'])->expectsOutputToContain('No stack named [ghost]')->assertFailed();
});
