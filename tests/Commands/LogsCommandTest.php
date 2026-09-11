<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Facades\Compose;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

it('streams the tail of a stack log', function (): void {
    Process::fake(['*' => Process::result("meili | started\n")]);
    Compose::register(fakeStack(['name' => 'meili']));

    artisan('compose:logs', ['stack' => 'meili', '--tail' => 20, '--service' => 'meilisearch'])
        ->expectsOutputToContain('meili | started')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -5) === ['logs', '--no-color', '--tail', '20', 'meilisearch']
        && $process->timeout === 60);
});

it('follows without a timeout and fails when compose fails', function (): void {
    Process::fake(['*' => Process::result('', 'no such service', 1)]);
    Compose::register(fakeStack(['name' => 'meili']));

    artisan('compose:logs', ['stack' => 'meili', '--follow' => true])->assertFailed();

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -5) === ['logs', '--no-color', '--tail', '100', '--follow']
        && $process->timeout === null);
});

it('reports an unknown stack', function (): void {
    Process::fake();

    artisan('compose:logs', ['stack' => 'ghost'])->expectsOutputToContain('No stack named [ghost]')->assertFailed();

    Process::assertNothingRan();
});
