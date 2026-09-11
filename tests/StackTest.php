<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\DiscoveredStack;
use Mozex\Compose\Stack;
use Mozex\Compose\Tests\Fixtures\Plain\Meilisearch\MeilisearchStack;

it('derives its directory and compose file from where the class lives', function (): void {
    $stack = new MeilisearchStack;

    expect(str_replace('\\', '/', $stack->directory()))->toBe(fixturesPath('Plain/Meilisearch'))
        ->and(str_replace('\\', '/', $stack->composePath()))->toBe(fixturesPath('Plain/Meilisearch/docker-compose.yml'))
        ->and($stack->compose()->name())->toBe('meilisearch');
});

it('names itself after the compose file, falling back to the directory', function (): void {
    expect((new MeilisearchStack)->name())->toBe('meilisearch')
        ->and((new DiscoveredStack(fixturesPath('Plain/Mailpit')))->name())->toBe('mailpit');
});

it('normalizes and validates names the way compose does', function (): void {
    expect(Stack::normalizeName('My Stack!'))->toBe('my-stack')
        ->and(Stack::normalizeName('__init'))->toBe('init')
        ->and(Stack::normalizeName('RDP Gateway v2'))->toBe('rdp-gateway-v2')
        ->and(Stack::isValidName('meilisearch'))->toBeTrue()
        ->and(Stack::isValidName('rdp_gateway-2'))->toBeTrue()
        ->and(Stack::isValidName('Meili'))->toBeFalse()
        ->and(Stack::isValidName('-lead'))->toBeFalse()
        ->and(Stack::isValidName(''))->toBeFalse();
});

it('reads container names from the compose file and ships quiet defaults', function (): void {
    $stack = new MeilisearchStack;

    expect($stack->containerNames())->toBe(['meilisearch'])
        ->and($stack->enabled())->toBeTrue()
        ->and($stack->profiles())->toBe([])
        ->and($stack->build())->toBeFalse()
        ->and($stack->pull())->toBeTrue()
        ->and($stack->wait())->toBeNull()
        ->and($stack->timeout())->toBeNull()
        ->and($stack->pullTimeout())->toBeNull()
        ->and($stack->buildTimeout())->toBeNull()
        ->and($stack->linkPath())->toBeNull()
        ->and($stack->host())->toBeNull()
        ->and($stack->context())->toBeNull();
});

it('runs its status, logs, exec, and down helpers through compose', function (): void {
    Process::fake([
        '*ps*' => Process::result('{"Name":"meilisearch","Service":"meilisearch","State":"running","Health":"healthy","Status":"Up","ExitCode":0,"Publishers":[]}'),
        '*logs*' => Process::result('line one'),
        '*' => Process::result(''),
    ]);

    $stack = new MeilisearchStack;
    $file = $stack->composePath();
    $directory = $stack->directory();

    expect($stack->isRunning())->toBeTrue()
        ->and($stack->isHealthy())->toBeTrue()
        ->and($stack->logs('meilisearch', 20))->toBe("line one\n")
        ->and($stack->exec('meilisearch', ['ls', '-la'])->successful())->toBeTrue()
        ->and($stack->down(volumes: true)->successful())->toBeTrue();

    $prefix = ['docker', 'compose', '--project-name', 'meilisearch', '--project-directory', $directory, '--file', $file];

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'ps', '--all', '--format', 'json'] && $process->path === $directory);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'logs', '--no-color', '--tail', '20', 'meilisearch']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'exec', '--no-TTY', 'meilisearch', 'ls', '-la']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'down', '--remove-orphans', '--volumes']);
});
