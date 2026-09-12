<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\DiscoveredStack;
use Mozex\Compose\Exceptions\ComposeException;
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

it('keeps a name when its compose file cannot be read', function (): void {
    $directory = temporaryDirectory();
    File::put($directory.'/docker-compose.yml', "services:\n    app: [\n");

    expect((new DiscoveredStack($directory))->name())->toBe(Stack::normalizeName(basename($directory)))
        ->and(fn () => (new DiscoveredStack($directory))->compose())->toThrow(ComposeException::class, 'could not be read');
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

it('resolves container names the way compose will: shell, then env file, then the project name', function (): void {
    $compose = "name: shop\nservices:\n    app:\n        image: alpine\n        container_name: \${COMPOSE_PROJECT_NAME}-\${COMPOSE_TEST_ROLE}\n";
    $classed = fakeStack(['name' => 'shop', 'compose' => $compose, 'environment' => ['COMPOSE_TEST_ROLE' => 'api']]);
    $handWritten = fakeStack(['name' => 'shop', 'compose' => $compose, 'environment' => []]);
    File::put($handWritten->directory().'/.env', "COMPOSE_TEST_ROLE=worker\n");
    $bare = fakeStack(['name' => 'shop', 'compose' => $compose, 'environment' => []]);

    expect($classed->containerNames())->toBe(['shop-api'])
        ->and($handWritten->containerNames())->toBe(['shop-worker'])
        ->and($bare->containerNames())->toBe(['shop-']);

    putenv('COMPOSE_TEST_ROLE=shell');

    try {
        expect($bare->containerNames())->toBe(['shop-shell'])
            ->and($classed->containerNames())->toBe(['shop-api']);
    } finally {
        putenv('COMPOSE_TEST_ROLE');
    }
});

it('runs its status, logs, exec, and down helpers through compose', function (): void {
    // Match on the arguments: a string pattern like `*ps*` would also match
    // a temp path holding those letters.
    Process::fake(fn (PendingProcess $process) => match (true) {
        in_array('ps', $process->command, true) => Process::result('{"Name":"meilisearch","Service":"meilisearch","State":"running","Health":"healthy","Status":"Up","ExitCode":0,"Publishers":[]}'),
        in_array('logs', $process->command, true) => Process::result('line one'),
        default => Process::result(''),
    });

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
