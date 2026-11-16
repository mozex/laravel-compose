<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Actions\RedeployStack;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Facades\Compose;
use Mozex\Compose\Stack;

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
            '        container_name: ${COMPOSE_PROJECT_NAME}-app',
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
    putenv('GREETING');
    unset($_SERVER['GREETING'], $_ENV['GREETING']);

    // The stack directory is already gone by now: temporary directories are
    // removed by the global hook first. `down` finds the project by name.
    if (isset($this->name)) {
        Process::timeout(60)->run(['docker', 'rm', '-f', $this->name.'-app']);
        Process::timeout(60)->run(['docker', 'compose', '--project-name', $this->name, 'down', '--remove-orphans', '--volumes']);
        // Built images carry the run's label, so the replaced first build,
        // left dangling by the second, goes too.
        Process::timeout(60)->run(['docker', 'image', 'prune', '--all', '--force', '--filter', 'label=laravel-compose.it='.$this->name]);
    }
});

/**
 * A stack whose one service is built from a Dockerfile in the stack
 * directory. The label tells which build the running container came from.
 */
function buildingStack(string $name, bool $build, string $mark): Stack
{
    $stack = fakeStack([
        'name' => $name,
        'build' => $build,
        'wait' => 30,
        'compose' => implode("\n", [
            "name: {$name}",
            'services:',
            '    app:',
            '        build: .',
            '        container_name: ${COMPOSE_PROJECT_NAME}-app',
            '        healthcheck:',
            '            test: ["CMD", "true"]',
            '            interval: 2s',
            '            timeout: 2s',
            '            retries: 3',
            '            start_period: 1s',
            '',
        ]),
    ]);

    markBuild($stack, $mark);

    return $stack;
}

function markBuild(Stack $stack, string $mark): void
{
    File::put($stack->directory().'/Dockerfile', implode("\n", [
        'FROM alpine:3',
        "LABEL laravel-compose.it={$stack->name()}",
        "LABEL laravel-compose.mark={$mark}",
        'CMD ["sleep", "120"]',
        '',
    ]));
}

function runningMark(string $container): string
{
    return trim(Process::timeout(30)->run(['docker', 'inspect', '--format', '{{index .Config.Labels "laravel-compose.mark"}}', $container])->output());
}

it('redeploys, reports status and logs, and tears down a real stack', function (): void {
    Compose::register($this->stack);
    $stack = $this->stack;

    // A stale container under a foreign project context must not wedge `up`;
    // its name only matches once ${COMPOSE_PROJECT_NAME} is resolved.
    Process::timeout(120)->run(['docker', 'run', '--detach', '--name', $this->name.'-app', 'alpine:3', 'sleep', '60']);

    // The shell has the same key as the env file, the way an app's own .env
    // reaches child processes: Laravel writes it to putenv, $_SERVER, and
    // $_ENV, and Symfony only forwards getenv() keys that $_SERVER also has.
    // The file has to win.
    putenv('GREETING=shadowed by the shell');
    $_SERVER['GREETING'] = $_ENV['GREETING'] = 'shadowed by the shell';

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
        ->and($stack->logs())->not->toContain('shadowed by the shell')
        ->and(trim($stack->exec('app', ['cat', '/etc/alpine-release'])->output()))->toMatch('/^3\./');

    artisan('compose:status', ['stack' => $this->name])->assertSuccessful();
    artisan('compose:logs', ['stack' => $this->name, '--tail' => 5])->expectsOutputToContain('hello from laravel-compose')->assertSuccessful();
    artisan('compose:doctor')->assertSuccessful();

    expect($stack->down(volumes: true)->successful())->toBeTrue()
        ->and($stack->status()->isEmpty())->toBeTrue();
});

it('starts the image the build step just made on every redeploy', function (): void {
    $stack = buildingStack($this->name, build: true, mark: 'first');

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Redeployed)
        ->and(runningMark($this->name.'-app'))->toBe('first');

    // A changed Dockerfile is built before the sweep and is what `up` starts.
    markBuild($stack, 'second');

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Redeployed)
        ->and(runningMark($this->name.'-app'))->toBe('second')
        ->and($stack->status()->isHealthy())->toBeTrue();
});

it('still builds a missing image at up for a stack that does not build', function (): void {
    $stack = buildingStack($this->name, build: false, mark: 'first');

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Redeployed)
        ->and(runningMark($this->name.'-app'))->toBe('first')
        ->and($stack->status()->isHealthy())->toBeTrue();
});
