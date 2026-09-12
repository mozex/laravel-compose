<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\DiscoveredStack;
use Mozex\Compose\Docker;
use Mozex\Compose\Exceptions\ComposeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

it('builds commands from the configured binary and context', function (): void {
    $docker = app(Docker::class);

    expect($docker->command(['info']))->toBe(['docker', 'info']);

    config()->set('compose.docker.binary', '/usr/local/bin/docker');
    config()->set('compose.docker.context', 'production');

    expect($docker->command(['info']))->toBe(['/usr/local/bin/docker', '--context', 'production', 'info'])
        ->and($docker->command(['ps'], fakeStack(['context' => 'staging'])))->toBe(['/usr/local/bin/docker', '--context', 'staging', 'ps']);

    config()->set('compose.docker.binary', '');
    expect($docker->binary())->toBe('docker');
});

it('passes the daemon address through DOCKER_HOST with the stack winning over config', function (): void {
    $docker = app(Docker::class);

    expect($docker->environment())->toBe([]);

    config()->set('compose.docker.host', 'ssh://deploy@docker-box');

    expect($docker->environment())->toBe(['DOCKER_HOST' => 'ssh://deploy@docker-box'])
        ->and($docker->environment(fakeStack(['host' => 'tcp://10.0.0.5:2376'])))->toBe(['DOCKER_HOST' => 'tcp://10.0.0.5:2376'])
        ->and($docker->host(fakeStack(['host' => '  '])))->toBe('ssh://deploy@docker-box');
});

it('knows when a stack targets a daemon on another machine', function (): void {
    $docker = app(Docker::class);

    expect($docker->isRemote())->toBeFalse()
        ->and($docker->isRemote(fakeStack(['host' => 'unix:///var/run/docker.sock'])))->toBeFalse()
        ->and($docker->isRemote(fakeStack(['host' => 'npipe:////./pipe/docker_engine'])))->toBeFalse()
        ->and($docker->isRemote(fakeStack(['host' => 'ssh://deploy@box'])))->toBeTrue()
        ->and($docker->isRemote(fakeStack(['host' => 'tcp://box:2376'])))->toBeTrue()
        ->and($docker->isRemote(fakeStack(['context' => 'remote'])))->toBeTrue()
        ->and($docker->isRemote(fakeStack(['context' => 'default'])))->toBeFalse();

    config()->set('compose.docker.context', 'remote');
    expect($docker->isRemote())->toBeTrue();
});

it('lets a stack replace both global values and drops a host beside a context', function (): void {
    $docker = app(Docker::class);
    config()->set('compose.docker.context', 'production');

    // A stack host alone means the local context, so `--context` is not passed and DOCKER_HOST is.
    $stack = fakeStack(['host' => 'ssh://deploy@box']);

    expect($docker->command(['ps'], $stack))->toBe(['docker', 'ps'])
        ->and($docker->environment($stack))->toBe(['DOCKER_HOST' => 'ssh://deploy@box'])
        ->and($docker->isRemote($stack))->toBeTrue();

    // Docker ignores DOCKER_HOST when --context is given, so the host is dropped rather than passed.
    config()->set('compose.docker.host', 'tcp://box:2376');

    expect($docker->command(['ps']))->toBe(['docker', '--context', 'production', 'ps'])
        ->and($docker->environment())->toBe([])
        ->and($docker->environment(fakeStack(['host' => 'tcp://other:2376', 'context' => 'staging'])))->toBe([])
        ->and($docker->isRemote(fakeStack(['host' => 'tcp://other:2376', 'context' => 'default'])))->toBeTrue();
});

it('refuses a log tail that is neither a number nor all', function (): void {
    Process::fake();

    expect(fn () => app(Docker::class)->logs(fakeStack(), tail: 'lots'))->toThrow(ComposeException::class, 'not [lots]');

    Process::assertNothingRan();
});

it('names the project, directory, file, and profiles on every compose call', function (): void {
    $stack = fakeStack(['name' => 'meili', 'profiles' => ['tls', 'metrics']]);
    $docker = app(Docker::class);
    $directory = $stack->directory();
    $file = $stack->composePath();

    expect($docker->composeArguments($stack, ['up']))->toBe([
        'compose', '--project-name', 'meili', '--project-directory', $directory, '--file', $file,
        '--profile', 'tls', '--profile', 'metrics', 'up',
    ]);
});

it('turns a process timeout into a failed result instead of an exception', function (): void {
    Process::fake(function (PendingProcess $process) {
        $symfony = new SymfonyProcess($process->command);

        throw new ProcessTimedOutException(new SymfonyTimedOutException($symfony, SymfonyTimedOutException::TYPE_GENERAL), new ProcessResult($symfony));
    });

    $result = app(Docker::class)->run(['info'], null, null, 1);

    expect($result->failed())->toBeTrue()
        ->and(app(Docker::class)->status(fakeStack())->isEmpty())->toBeTrue();
});

it('passes `all` through to the log tail', function (): void {
    Process::fake();

    app(Docker::class)->logs(fakeStack(), tail: 'all');
    app(Docker::class)->logs(fakeStack(), tail: 25);

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -2) === ['--tail', 'all']);
    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -2) === ['--tail', '25']);
});

it('runs processes with the stack directory, timeout, and daemon environment', function (): void {
    Process::fake();
    $stack = fakeStack(['host' => 'ssh://deploy@box']);
    $directory = $stack->directory();

    app(Docker::class)->compose($stack, ['ps'], 45);
    app(Docker::class)->run(['rm', '-f', 'x'], $stack, null, 5);

    Process::assertRan(fn (PendingProcess $process): bool => end($process->command) === 'ps'
        && $process->path === $directory
        && $process->timeout === 45
        && $process->environment === ['FAKE_KEY' => false, 'DOCKER_HOST' => 'ssh://deploy@box']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['docker', 'rm', '-f', 'x']
        && $process->path === null
        && $process->timeout === 5);
});

it('unsets the keys the stack writes so the env file beats the shell, for compose calls only', function (): void {
    Process::fake();
    $stack = fakeStack(['environment' => ['MEILI_PORT' => 7700, 'MEILI_KEY' => 'k']]);

    app(Docker::class)->compose($stack, ['ps']);
    app(Docker::class)->run(['rm', '-f', 'x'], $stack);

    Process::assertRan(fn (PendingProcess $process): bool => end($process->command) === 'ps' && $process->environment === ['MEILI_PORT' => false, 'MEILI_KEY' => false]);
    Process::assertRan(fn (PendingProcess $process): bool => end($process->command) === 'x' && $process->environment === []);

    $handWritten = fakeStack(['environment' => []]);
    File::put($handWritten->directory().'/.env', "HAND=1\n");

    app(Docker::class)->compose($handWritten, ['ps']);

    Process::assertRan(fn (PendingProcess $process): bool => end($process->command) === 'ps' && $process->environment === ['HAND' => false]);
});

it('still runs compose for a stack whose environment() throws', function (): void {
    Process::fake();
    $directory = temporaryDirectory();
    File::put($directory.'/docker-compose.yml', "name: touchy\nservices:\n    app:\n        image: alpine\n");
    $touchy = new class($directory) extends DiscoveredStack
    {
        public function environment(): array
        {
            throw new RuntimeException('vault unavailable');
        }
    };

    expect(app(Docker::class)->status($touchy)->isEmpty())->toBeTrue();

    Process::assertRan(fn (PendingProcess $process): bool => in_array('ps', $process->command, true) && $process->environment === []);
});

it('falls back to a default when a configured timeout is unusable', function (): void {
    $docker = app(Docker::class);

    expect($docker->timeout('pull', 300))->toBe(300);

    config()->set('compose.timeouts.pull', 45);
    expect($docker->timeout('pull', 300))->toBe(45);

    config()->set('compose.timeouts.pull', 'soon');
    expect($docker->timeout('pull', 300))->toBe(300);

    config()->set('compose.timeouts.pull', 0);
    expect($docker->timeout('pull', 300))->toBe(300);
});

it('reads an empty status when compose ps fails', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(app(Docker::class)->status(fakeStack())->isEmpty())->toBeTrue();
});
