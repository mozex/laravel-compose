<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Facades\Compose;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

it('redeploys every stack and reports each outcome', function (): void {
    Process::fake();
    Compose::register(fakeStack(['name' => 'on']))->register(fakeStack(['name' => 'off', 'enabled' => false]));

    artisan('compose:redeploy')
        ->expectsOutputToContain('Stack [on] redeployed.')
        ->expectsOutputToContain('Stack [off] is not enabled on this host. Skipped.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => in_array('up', $process->command, true));
});

it('fails when a stack fails and when the stack is unknown', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Compose::register(fakeStack(['name' => 'broken']));

    artisan('compose:redeploy', ['stack' => 'broken'])
        ->expectsOutputToContain('Stack [broken] failed to redeploy.')
        ->assertFailed();

    artisan('compose:redeploy', ['stack' => 'ghost'])
        ->expectsOutputToContain('No stack named [ghost] is registered.')
        ->assertFailed();
});

it('says so when nothing is registered', function (): void {
    Process::fake();

    artisan('compose:redeploy')->expectsOutputToContain('No stacks are registered.')->assertSuccessful();

    Process::assertNothingRan();
});

it('prints the plan on a dry run without touching docker or the env file', function (): void {
    Process::fake();
    $stack = fakeStack(['name' => 'meili', 'environment' => ['KEY' => 'secret', 'PORT' => 7700], 'profiles' => ['tls'], 'wait' => 60]);
    Compose::register($stack)->register(fakeStack(['name' => 'off', 'enabled' => false]));

    // Each substring must be unique to one output line: Laravel matches them
    // against single write calls, and a substring shared by two lines swallows both.
    artisan('compose:redeploy', ['--dry-run' => true])
        ->expectsOutputToContain('.env with KEY, PORT')
        ->expectsOutputToContain('--profile tls pull --ignore-buildable --quiet')
        ->expectsOutputToContain('$ docker rm -f meili-app')
        ->expectsOutputToContain('--profile tls up --detach --remove-orphans --wait --wait-timeout 60')
        ->expectsOutputToContain('skipped: not enabled on this host')
        ->assertSuccessful();

    expect(File::exists($stack->directory().'/.env'))->toBeFalse();
    Process::assertNothingRan();
});

it('includes the build step in a dry run for building stacks', function (): void {
    Process::fake();
    Compose::register(fakeStack(['name' => 'built', 'build' => true, 'pull' => false, 'containers' => []]));

    artisan('compose:redeploy', ['stack' => 'built', '--dry-run' => true])
        ->expectsOutputToContain('build --pull')
        ->expectsOutputToContain('up --detach --remove-orphans --build')
        ->doesntExpectOutputToContain('rm -f')
        ->assertSuccessful();
});
