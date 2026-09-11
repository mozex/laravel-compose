<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Actions\RedeployStack;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Events\StackRedeployedEvent;
use Mozex\Compose\Events\StackRedeployFailedEvent;
use Mozex\Compose\Events\StackRedeployingEvent;
use Mozex\Compose\Events\StackSkippedEvent;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Mozex\Compose\Support\OperatorLink;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

function composePrefix(Stack $stack): array
{
    return ['docker', 'compose', '--project-name', $stack->name(), '--project-directory', $stack->directory(), '--file', $stack->composePath()];
}

it('skips a disabled stack without touching docker or its env file', function (): void {
    Process::fake();
    Event::fake();
    $stack = fakeStack(['enabled' => false]);

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Skipped)
        ->and(File::exists($stack->directory().'/.env'))->toBeFalse();

    Process::assertNothingRan();
    Event::assertDispatched(StackRedeployingEvent::class);
    Event::assertDispatched(StackSkippedEvent::class, fn (StackSkippedEvent $event): bool => $event->stack === $stack);
    Event::assertNotDispatched(StackRedeployedEvent::class);
});

it('skips every stack when the master switch is off', function (): void {
    Process::fake();
    config()->set('compose.enabled', false);

    expect(app(RedeployStack::class)->execute(fakeStack()))->toBe(RedeployResult::Skipped);

    Process::assertNothingRan();
});

it('writes the env file, pulls, sweeps stale containers, and recreates the stack in that order', function (): void {
    $sequence = [];
    Process::fake(function (PendingProcess $process) use (&$sequence) {
        $sequence[] = [$process->command, $process->timeout, $process->path];

        return Process::result();
    });
    Event::fake();
    $stack = fakeStack([
        'name' => 'meili',
        'environment' => ['MEILISEARCH_PORT' => 7700, 'MEILISEARCH_KEY' => 'secret value'],
        'containers' => ['meili-a', 'meili-b'],
    ]);
    $prefix = composePrefix($stack);

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Redeployed)
        ->and(File::get($stack->directory().'/.env'))->toBe("MEILISEARCH_PORT=7700\nMEILISEARCH_KEY='secret value'\n")
        ->and($sequence)->toBe([
            [[...$prefix, 'pull', '--ignore-buildable', '--quiet'], 300, $stack->directory()],
            [['docker', 'rm', '-f', 'meili-a', 'meili-b'], 30, null],
            [[...$prefix, 'up', '--detach', '--remove-orphans'], 120, $stack->directory()],
        ]);

    Event::assertDispatched(StackRedeployedEvent::class, fn (StackRedeployedEvent $event): bool => $event->stack === $stack);
});

it('activates the compose profiles on the way up and honours per-stack knobs', function (): void {
    Process::fake();
    $stack = fakeStack(['profiles' => ['tls'], 'pull' => false, 'containers' => [], 'wait' => 200, 'timeout' => 90]);
    $prefix = [...composePrefix($stack), '--profile', 'tls'];

    app(RedeployStack::class)->execute($stack);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'up', '--detach', '--remove-orphans', '--wait', '--wait-timeout', '200']
        && $process->timeout === 230);
    Process::assertNotRan(fn (PendingProcess $process): bool => in_array('pull', $process->command, true));
    Process::assertNotRan(fn (PendingProcess $process): bool => in_array('rm', $process->command, true));
});

it('builds images first for a stack that asks to, and fails on a broken build', function (): void {
    Process::fake(['*build*' => Process::result(exitCode: 1), '*' => Process::result()]);
    Event::fake();
    $stack = fakeStack(['build' => true]);
    $prefix = composePrefix($stack);

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Failed);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'build', '--pull'] && $process->timeout === 600);
    Process::assertNotRan(fn (PendingProcess $process): bool => in_array('up', $process->command, true));
    Event::assertDispatched(StackRedeployFailedEvent::class, fn (StackRedeployFailedEvent $event): bool => $event->step === 'build');

    Process::fake();

    app(RedeployStack::class)->execute($stack);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...$prefix, 'up', '--detach', '--remove-orphans', '--build']);
});

it('reports a failed compose up while ignoring the pull and sweep exit codes', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Event::fake();
    $stack = fakeStack();

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Failed);

    Event::assertDispatched(StackRedeployFailedEvent::class, fn (StackRedeployFailedEvent $event): bool => $event->step === 'up' && $event->result->failed());
    Event::assertNotDispatched(StackRedeployedEvent::class);
});

it('treats a timed-out up as a failure and a timed-out pull as noise', function (): void {
    // One closure per scenario: array fakes merge across calls, and a `*`
    // catch-all from an earlier call would shadow a later specific pattern.
    $timeoutOn = fn (string $step) => function (PendingProcess $process) use ($step) {
        if (! in_array($step, $process->command, true)) {
            return Process::result();
        }

        $symfony = new SymfonyProcess($process->command);

        throw new ProcessTimedOutException(new SymfonyTimedOutException($symfony, SymfonyTimedOutException::TYPE_GENERAL), new ProcessResult($symfony));
    };
    Event::fake();
    $stack = fakeStack(['name' => 'slow']);

    Process::fake($timeoutOn('pull'));

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Redeployed);

    Process::fake($timeoutOn('up'));

    expect(app(RedeployStack::class)->execute($stack))->toBe(RedeployResult::Failed);

    Event::assertDispatched(StackRedeployFailedEvent::class, fn (StackRedeployFailedEvent $event): bool => $event->step === 'up' && $event->result->failed());
});

it('leaves a hand-written env file alone when the stack has nothing to write', function (): void {
    Process::fake();
    $stack = fakeStack(['environment' => []]);
    File::put($stack->directory().'/.env', "HAND_WRITTEN=yes\n");

    app(RedeployStack::class)->execute($stack);

    expect(File::get($stack->directory().'/.env'))->toBe("HAND_WRITTEN=yes\n");

    $fresh = fakeStack(['environment' => []]);

    app(RedeployStack::class)->execute($fresh);

    expect(File::get($fresh->directory().'/.env'))->toBe('');
});

it('fails the stack on an env value it cannot write, before running anything', function (): void {
    Process::fake();
    Event::fake();
    $stack = fakeStack(['name' => 'bad-env', 'environment' => ['BAD' => "a\nb"]]);
    $lines = [];

    $result = app(RedeployStack::class)->execute($stack, function (string $type, string $buffer) use (&$lines): void {
        $lines[] = $type.':'.$buffer;
    });

    expect($result)->toBe(RedeployResult::Failed)
        ->and(File::exists($stack->directory().'/.env'))->toBeFalse()
        ->and(implode('', $lines))->toContain('err:The env file for [bad-env] could not be written: The environment value for [BAD] on stack [bad-env] contains a line break');

    Process::assertNothingRan();
    Event::assertDispatched(StackRedeployFailedEvent::class, fn (StackRedeployFailedEvent $event): bool => $event->step === 'env'
        && $event->result === null
        && $event->exception instanceof ComposeException
        && str_contains($event->reason(), 'contains a line break'));
});

it('carries the process reason on a failed step', function (): void {
    Process::fake(fn (PendingProcess $process) => in_array('up', $process->command, true) ? Process::result('stdout only', '', 1) : Process::result());
    Event::fake();

    app(RedeployStack::class)->execute(fakeStack());

    Event::assertDispatched(StackRedeployFailedEvent::class, fn (StackRedeployFailedEvent $event): bool => $event->reason() === 'stdout only');
});

it('reports a wedged operator link without failing the rollout', function (): void {
    Process::fake();
    Exceptions::fake();
    $stack = fakeStack(['name' => 'wedged']);

    $link = Mockery::mock(OperatorLink::class);
    $link->shouldReceive('refresh')->once()->andThrow(new RuntimeException('link path wedged'));
    app()->instance(OperatorLink::class, $link);

    $lines = [];
    $result = app(RedeployStack::class)->execute($stack, function (string $type, string $buffer) use (&$lines): void {
        $lines[] = $type.':'.$buffer;
    });

    expect($result)->toBe(RedeployResult::Redeployed)
        ->and(implode('', $lines))->toContain('err:Operator link for [wedged] failed and was skipped: link path wedged');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'link path wedged');
    Process::assertRan(fn (PendingProcess $process): bool => in_array('up', $process->command, true));
});

it('links the stack for the operator when a link directory is configured', function (): void {
    Process::fake();
    config()->set('compose.link_directory', temporaryDirectory());
    $stack = fakeStack(['name' => 'linked']);

    app(RedeployStack::class)->execute($stack);

    $link = config('compose.link_directory').'/linked';
    clearstatcache();

    expect(File::exists($link.'/docker-compose.yml'))->toBeTrue()
        ->and(File::exists($link.'/.env'))->toBeTrue();

    @rmdir($link) || @unlink($link);
});

it('skips the operator link when the daemon lives on another machine', function (): void {
    Process::fake();
    config()->set('compose.link_directory', temporaryDirectory());
    $stack = fakeStack(['name' => 'remote', 'host' => 'ssh://deploy@box']);

    app(RedeployStack::class)->execute($stack);

    expect(File::exists(config('compose.link_directory').'/remote'))->toBeFalse();
    Process::assertRan(fn (PendingProcess $process): bool => $process->environment === ['DOCKER_HOST' => 'ssh://deploy@box'] && in_array('up', $process->command, true));
});

it('writes the env file under the configured name', function (): void {
    Process::fake();
    config()->set('compose.env_file', '.env.stack');
    $stack = fakeStack();

    app(RedeployStack::class)->execute($stack);

    expect(File::exists($stack->directory().'/.env.stack'))->toBeTrue()
        ->and(File::exists($stack->directory().'/.env'))->toBeFalse();
});
