<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Actions\RedeployStack;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Facades\Compose;

use function Pest\Laravel\artisan;

/*
 * Runs the real recipe against a real daemon with a throwaway alpine
 * container. Skipped wherever docker compose or the daemon is missing, so the
 * rest of the suite never depends on Docker. Everything the test creates
 * carries a unique name and is removed afterwards.
 */

beforeEach(function (): void {
    if (! dockerComposeAvailable()) {
        $this->markTestSkipped('docker compose is not available on this machine.');
    }

    $daemon = Process::timeout(20)->run(['docker', 'info', '--format', '{{.ServerVersion}}']);

    if ($daemon->failed()) {
        $this->markTestSkipped('The Docker daemon is not reachable: '.trim($daemon->errorOutput()));
    }

    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);

    $this->name = 'laravel-compose-it-'.getmypid().'-'.substr(uniqid(), -6);
    $this->stack = fakeStack([
        'name' => $this->name,
        'wait' => 30,
        'environment' => ['GREETING' => 'hello from laravel-compose', 'SLEEP' => 120],
        'compose' => implode("\n", [
            "name: {$this->name}",
            'services:',
            '    app:',
            '        image: alpine:3',
            "        container_name: {$this->name}-app",
            '        command: sh -c "echo $GREETING && sleep ${SLEEP:-60}"',
            '        environment:',
            '            GREETING: ${GREETING}',
            '        healthcheck:',
            '            test: ["CMD", "true"]',
            '            interval: 2s',
            '            timeout: 2s',
            '            retries: 3',
            '            start_period: 1s',
            '',
        ]),
    ]);
});

afterEach(function (): void {
    if (isset($this->name)) {
        Process::timeout(60)->run(['docker', 'rm', '-f', $this->name.'-app']);
        Process::timeout(60)->path($this->stack->directory())->run(['docker', 'compose', '--project-name', $this->name, 'down', '--remove-orphans', '--volumes']);
        File::deleteDirectory($this->stack->directory());
    }
});

it('redeploys, reports status and logs, and tears down a real stack', function (): void {
    Compose::register($this->stack);
    $stack = $this->stack;

    // A stale container under a foreign project context must not wedge `up`.
    Process::timeout(120)->run(['docker', 'run', '--detach', '--name', $this->name.'-app', 'alpine:3', 'sleep', '60']);

    $result = app(RedeployStack::class)->execute($stack, function (string $type, string $buffer): void {
        // Streamed compose output; nothing to assert on its exact wording.
    });

    expect($result)->toBe(RedeployResult::Redeployed)
        ->and(File::get($stack->directory().'/.env'))->toBe("GREETING='hello from laravel-compose'\nSLEEP=120\n");

    // `up --wait` returned, so the healthcheck already passed once.
    $status = $stack->status();

    expect($status->isRunning())->toBeTrue(json_encode($status))
        ->and($status->isHealthy())->toBeTrue(json_encode($status))
        ->and($status->containers[0]->name)->toBe($this->name.'-app')
        ->and($status->containers[0]->service)->toBe('app')
        ->and($stack->logs())->toContain('hello from laravel-compose')
        ->and(trim($stack->exec('app', ['cat', '/etc/alpine-release'])->output()))->toMatch('/^3\./');

    artisan('compose:status', ['stack' => $this->name])->assertSuccessful();
    artisan('compose:logs', ['stack' => $this->name, '--tail' => 5])->expectsOutputToContain('hello from laravel-compose')->assertSuccessful();
    artisan('compose:doctor')->assertSuccessful();

    expect($stack->down(volumes: true)->successful())->toBeTrue()
        ->and($stack->status()->isEmpty())->toBeTrue();
});
