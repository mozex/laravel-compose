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

it('stops every stack, or only the named one', function (): void {
    Process::fake();
    Compose::register(fakeStack(['name' => 'one']))->register(fakeStack(['name' => 'two']));

    artisan('compose:down')
        ->expectsOutputToContain('Stack [one] stopped.')
        ->expectsOutputToContain('Stack [two] stopped.')
        ->assertSuccessful();

    Process::assertRanTimes(fn (PendingProcess $process): bool => array_slice($process->command, -2) === ['down', '--remove-orphans'], 2);

    artisan('compose:down', ['stack' => 'two'])->assertSuccessful();

    // The recorder keeps the first run's two processes, so `two` now shows twice and `one` still once.
    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('down', $process->command, true) && in_array('two', $process->command, true), 2);
    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('down', $process->command, true) && in_array('one', $process->command, true), 1);
});

it('skips disabled stacks unless one is named explicitly', function (): void {
    Process::fake();
    Compose::register(fakeStack(['name' => 'on']))->register(fakeStack(['name' => 'off', 'enabled' => false]));

    artisan('compose:down')
        ->expectsOutputToContain('Stack [off] is not enabled on this host. Skipped.')
        ->expectsOutputToContain('Stack [on] stopped.')
        ->assertSuccessful();

    Process::assertNotRan(fn (PendingProcess $process): bool => in_array('off', $process->command, true));

    artisan('compose:down', ['stack' => 'off'])->expectsOutputToContain('Stack [off] stopped.')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => in_array('off', $process->command, true) && in_array('down', $process->command, true));
});

it('asks before removing volumes and honours --force', function (): void {
    Process::fake();
    Compose::register(fakeStack(['name' => 'data']));

    artisan('compose:down', ['--volumes' => true])
        ->expectsConfirmation('This also removes the named volumes and the data inside them. Continue?', 'no')
        ->expectsOutputToContain('Nothing was stopped.')
        ->assertFailed();

    Process::assertNothingRan();

    artisan('compose:down', ['--volumes' => true, '--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -3) === ['down', '--remove-orphans', '--volumes']);
});

it('reports failures and unknown stacks', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Compose::register(fakeStack(['name' => 'stuck']));

    artisan('compose:down')->expectsOutputToContain('Stack [stuck] failed to stop.')->assertFailed();
    artisan('compose:down', ['stack' => 'ghost'])->expectsOutputToContain('No stack named [ghost]')->assertFailed();
});

it('says so when nothing is registered', function (): void {
    Process::fake();

    artisan('compose:down')->expectsOutputToContain('No stacks are registered.')->assertSuccessful();

    Process::assertNothingRan();
});
