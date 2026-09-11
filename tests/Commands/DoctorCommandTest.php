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

it('prints every finding and passes with warnings only', function (): void {
    Process::fake(['*' => Process::result('29.0.1')]);
    Compose::register(fakeStack([
        'name' => 'open',
        'compose' => "name: open\nservices:\n    app:\n        image: alpine\n        ports:\n            - '80:80'\n",
    ]));

    artisan('compose:doctor')
        ->expectsOutputToContain('Docker client 29.0.1.')
        ->expectsOutputToContain('[open] Publishes on every interface: app: 80:80.')
        ->expectsOutputToContain('No errors, 1 warning(s).')
        ->assertSuccessful();
});

it('fails on errors and skips the daemon when asked', function (): void {
    Process::fake();
    Compose::register(fakeStack([
        'name' => 'needy',
        'compose' => "name: needy\nservices:\n    app:\n        image: alpine\n        environment:\n            SECRET: \${SECRET}\n",
        'environment' => [],
    ]));

    artisan('compose:doctor', ['--no-daemon' => true])
        ->expectsOutputToContain('[needy] The compose file consumes SECRET without a default')
        ->expectsOutputToContain('1 error(s), 0 warning(s).')
        ->assertFailed();

    Process::assertNothingRan();
});

it('says everything checks out on a clean host', function (): void {
    Process::fake(['*' => Process::result('29.0.1')]);
    Compose::register(fakeStack(['name' => 'fine']));

    artisan('compose:doctor')->expectsOutputToContain('Everything checks out.')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => in_array('config', $process->command, true));
});
