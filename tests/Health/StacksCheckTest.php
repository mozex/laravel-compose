<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Mozex\Compose\Facades\Compose;
use Mozex\Compose\Health\StacksCheck;
use Spatie\Health\Enums\Status;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

function containerRow(string $state, string $health = '', int $exitCode = 0): string
{
    return json_encode(['Name' => 'c', 'Service' => 's', 'State' => $state, 'Health' => $health, 'Status' => $state, 'ExitCode' => $exitCode, 'Publishers' => []], JSON_THROW_ON_ERROR);
}

it('passes when every enabled stack runs and reports each one in the meta', function (): void {
    Process::fake(['*' => Process::result(containerRow('running', 'healthy'))]);
    Compose::register(fakeStack(['name' => 'meili']))->register(fakeStack(['name' => 'off', 'enabled' => false]));

    $result = StacksCheck::new()->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta)->toBe(['meili' => 'healthy', 'off' => 'disabled'])
        ->and($result->getNotificationMessage())->toBe('Every enabled stack is running.');
});

it('fails when an enabled stack is down or was never created', function (): void {
    Process::fake([
        '*--project-name*gone*' => Process::result(''),
        '*--project-name*dead*' => Process::result(containerRow('exited', '', 1)),
        '*' => Process::result(containerRow('running')),
    ]);
    Compose::register(fakeStack(['name' => 'gone']))->register(fakeStack(['name' => 'dead']))->register(fakeStack(['name' => 'fine']));

    $result = StacksCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->getNotificationMessage())->toBe('Stacks not running: gone, dead.')
        ->and($result->meta)->toBe(['gone' => 'not created', 'dead' => 'down', 'fine' => 'healthy']);
});

it('warns when a stack runs but its healthcheck is not green', function (): void {
    Process::fake(['*' => Process::result(containerRow('running', 'starting'))]);
    Compose::register(fakeStack(['name' => 'slow']));

    $result = StacksCheck::new()->run();

    expect($result->status)->toBe(Status::warning())
        ->and($result->getNotificationMessage())->toBe('Stacks unhealthy: slow.')
        ->and($result->meta)->toBe(['slow' => 'unhealthy']);
});

it('passes quietly when compose is disabled or nothing is registered', function (): void {
    Process::fake();

    expect(StacksCheck::new()->run()->getNotificationMessage())->toBe('No stacks are registered.');

    config()->set('compose.enabled', false);

    expect(StacksCheck::new()->run()->status)->toBe(Status::ok())
        ->and(StacksCheck::new()->run()->getNotificationMessage())->toBe('Compose is disabled on this host.');

    Process::assertNothingRan();
});
