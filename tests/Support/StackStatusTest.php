<?php

declare(strict_types=1);

use Mozex\Compose\Support\StackStatus;

function statusRow(string $name, string $state, ?string $health = null, int $exitCode = 0, array $publishers = []): string
{
    return json_encode([
        'Name' => $name,
        'Service' => $name,
        'State' => $state,
        'Health' => $health ?? '',
        'Status' => ucfirst($state),
        'ExitCode' => $exitCode,
        'Publishers' => $publishers,
    ], JSON_THROW_ON_ERROR);
}

it('parses one object per line and a json array alike', function (): void {
    $lines = statusRow('a', 'running')."\n".statusRow('b', 'running');
    $array = '['.statusRow('a', 'running').','.statusRow('b', 'running').']';

    expect(StackStatus::fromJson('stack', $lines)->containers)->toHaveCount(2)
        ->and(StackStatus::fromJson('stack', $array)->containers)->toHaveCount(2)
        ->and(StackStatus::fromJson('stack', statusRow('only', 'running'))->containers)->toHaveCount(1)
        ->and(StackStatus::fromJson('stack', '')->isEmpty())->toBeTrue()
        ->and(StackStatus::fromJson('stack', 'not json')->isEmpty())->toBeTrue();
});

it('judges running and healthy from every container', function (): void {
    $healthy = StackStatus::fromJson('s', statusRow('app', 'running', 'healthy')."\n".statusRow('init', 'exited', null, 0));
    $unhealthy = StackStatus::fromJson('s', statusRow('app', 'running', 'unhealthy'));
    $crashed = StackStatus::fromJson('s', statusRow('app', 'running')."\n".statusRow('init', 'exited', null, 1));
    $starting = StackStatus::fromJson('s', statusRow('app', 'running', 'starting'));
    $noHealthcheck = StackStatus::fromJson('s', statusRow('app', 'running'));

    expect($healthy->isRunning())->toBeTrue()
        ->and($healthy->isHealthy())->toBeTrue()
        ->and($unhealthy->isRunning())->toBeTrue()
        ->and($unhealthy->isHealthy())->toBeFalse()
        ->and($crashed->isRunning())->toBeFalse()
        ->and($crashed->isHealthy())->toBeFalse()
        ->and($starting->isHealthy())->toBeFalse()
        ->and($noHealthcheck->isHealthy())->toBeTrue()
        ->and(StackStatus::fromJson('s', '')->isRunning())->toBeFalse();
});

it('describes published ports and tolerates missing fields', function (): void {
    $status = StackStatus::fromJson('s', statusRow('app', 'running', null, 0, [
        ['URL' => '127.0.0.1', 'TargetPort' => 7700, 'PublishedPort' => 7701, 'Protocol' => 'tcp'],
        ['URL' => '', 'TargetPort' => 9000, 'PublishedPort' => 0, 'Protocol' => 'tcp'],
        ['URL' => '::', 'TargetPort' => 53, 'PublishedPort' => 53, 'Protocol' => 'udp'],
        'garbage',
    ]));
    $bare = StackStatus::fromJson('s', '{"Name":"x"}');

    expect($status->containers[0]->ports)->toBe(['127.0.0.1:7701->7700/tcp', ':::53->53/udp'])
        ->and($status->containers[0]->health)->toBeNull()
        ->and($bare->containers[0]->state)->toBe('unknown')
        ->and($bare->containers[0]->service)->toBe('')
        ->and($bare->containers[0]->exitCode)->toBe(0);
});
