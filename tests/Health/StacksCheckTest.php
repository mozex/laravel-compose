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

it('fails when a stack runs without the ports its compose file publishes', function (): void {
    $published = "name: meili\nservices:\n    app:\n        image: alpine:3\n        container_name: meili-app\n        ports:\n            - '127.0.0.1:7700:7700'\n";
    $row = fn (array $publishers, string $health = 'healthy'): string => json_encode(['Name' => 'meili-app', 'Service' => 'app', 'State' => 'running', 'Health' => $health, 'Status' => 'Up', 'ExitCode' => 0, 'Publishers' => $publishers], JSON_THROW_ON_ERROR);
    Process::fake([
        // Still starting as well: losing every port outranks a pending healthcheck.
        '*--project-name*meili*' => Process::result($row([['URL' => '', 'TargetPort' => 7700, 'PublishedPort' => 0, 'Protocol' => 'tcp']], 'starting')),
        '*--project-name*bound*' => Process::result($row([['URL' => '127.0.0.1', 'TargetPort' => 7700, 'PublishedPort' => 7700, 'Protocol' => 'tcp']])),
        '*--project-name*dead*' => Process::result(containerRow('exited', '', 1)),
        // The same service name, but these compose files declare no ports or cannot be read.
        '*' => Process::result($row([])),
    ]);
    Compose::register(fakeStack(['name' => 'meili', 'compose' => $published]))
        ->register(fakeStack(['name' => 'bound', 'compose' => str_replace('meili', 'bound', $published)]))
        ->register(fakeStack(['name' => 'dead']))
        ->register(fakeStack(['name' => 'worker']))
        ->register(fakeStack(['name' => 'broken', 'compose' => "services: [unclosed\n"]));

    $result = StacksCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->getNotificationMessage())->toBe('Stacks not running: dead. Stacks running without their published ports: meili (meili-app).')
        ->and($result->meta)->toBe(['meili' => 'no ports', 'bound' => 'healthy', 'dead' => 'down', 'worker' => 'healthy', 'broken' => 'healthy']);
});

it('passes quietly when compose is disabled or nothing is registered', function (): void {
    Process::fake();

    expect(StacksCheck::new()->run()->getNotificationMessage())->toBe('No stacks are registered.');

    config()->set('compose.enabled', false);

    expect(StacksCheck::new()->run()->status)->toBe(Status::ok())
        ->and(StacksCheck::new()->run()->getNotificationMessage())->toBe('Compose is disabled on this host.');

    Process::assertNothingRan();
});
