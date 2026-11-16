<?php

declare(strict_types=1);

namespace Mozex\Compose\Actions;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Mozex\Compose\Docker;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Events\StackRedeployedEvent;
use Mozex\Compose\Events\StackRedeployFailedEvent;
use Mozex\Compose\Events\StackRedeployingEvent;
use Mozex\Compose\Events\StackSkippedEvent;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Mozex\Compose\Support\EnvFile;
use Mozex\Compose\Support\OperatorLink;
use Throwable;

/**
 * Recreates one stack from the current release. The order matters:
 *
 * 1. Write the env file. Compose reads it at `up`, so it has to land first.
 * 2. Refresh the operator link. Non-fatal: it is reported and printed.
 * 3. `compose pull`, result ignored. `up` never refreshes a tag it already
 *    has, and a registry outage should still redeploy the local image. It
 *    runs before the sweep so the old container serves during the download.
 * 4. `compose build --pull` when the stack builds its own images. After the
 *    pull, so the build is the last writer of a tag a service without a
 *    `build:` section shares; `--ignore-buildable` does not skip that one.
 *    Before the sweep, so the old container serves during the build.
 * 5. `docker rm -f` on the fixed container names, result ignored. A container
 *    created under a different project context is invisible to `up` and
 *    wedges it with a name conflict; a missing container is the normal case.
 * 6. `compose up --detach --remove-orphans`, with --wait when the stack asks.
 *    Never `--build`: it would build again after the sweep, with nothing
 *    serving, under the `up` timeout. Never `--no-build` either: `up` still
 *    builds an image that is missing, as on a first deploy.
 *
 * A step that runs past its timeout counts as a failed step: ignored for
 * pull and rm, fatal for build and up. A compose file that cannot be read or
 * a value that cannot be written to the env file fails the stack the same
 * way, so one stack's bad config never stops the others from getting their
 * turn. Idempotent end to end, so it is safe to run outside a deploy.
 */
class RedeployStack
{
    public function __construct(
        protected Docker $docker,
        protected EnvFile $envFile,
        protected OperatorLink $link,
        protected Dispatcher $events,
        protected Repository $config,
        protected ExceptionHandler $exceptions,
    ) {}

    /**
     * @param  (Closure(string, string): void)|null  $output  Receives the process output type and buffer
     */
    public function execute(Stack $stack, ?Closure $output = null): RedeployResult
    {
        $this->events->dispatch(new StackRedeployingEvent($stack));

        if (! $this->config->get('compose.enabled', true) || ! $stack->enabled()) {
            $this->events->dispatch(new StackSkippedEvent($stack));

            return RedeployResult::Skipped;
        }

        try {
            $stack->compose();
        } catch (ComposeException $exception) {
            return $this->fail($stack, 'compose', $exception, $output);
        }

        try {
            $this->writeEnvironment($stack);
        } catch (Throwable $exception) {
            return $this->fail($stack, 'env', $exception, $output);
        }

        $this->refreshLink($stack, $output);

        if ($stack->pull()) {
            $this->docker->compose($stack, ['pull', '--ignore-buildable', '--quiet'], $stack->pullTimeout() ?? $this->docker->timeout('pull', 300), $output);
        }

        if ($stack->build()) {
            $build = $this->docker->compose($stack, ['build', '--pull'], $stack->buildTimeout() ?? $this->docker->timeout('build', 600), $output);

            if ($build->failed()) {
                $this->events->dispatch(new StackRedeployFailedEvent($stack, 'build', $build));

                return RedeployResult::Failed;
            }
        }

        $names = $stack->containerNames();

        if ($names !== []) {
            $this->docker->run(['rm', '-f', ...$names], $stack, null, $this->docker->timeout('remove', 30));
        }

        $up = $this->docker->compose($stack, $this->upArguments($stack), $this->upTimeout($stack), $output);

        if ($up->failed()) {
            $this->events->dispatch(new StackRedeployFailedEvent($stack, 'up', $up));

            return RedeployResult::Failed;
        }

        $this->events->dispatch(new StackRedeployedEvent($stack));

        return RedeployResult::Redeployed;
    }

    /**
     * @return list<string>
     */
    public function upArguments(Stack $stack): array
    {
        $arguments = ['up', '--detach', '--remove-orphans'];

        $wait = $stack->wait();

        if ($wait !== null) {
            $arguments[] = '--wait';
            $arguments[] = '--wait-timeout';
            $arguments[] = (string) max(1, $wait);
        }

        return $arguments;
    }

    public function envPath(Stack $stack): string
    {
        return $this->envFile->pathFor($stack);
    }

    /**
     * A stack with nothing to write leaves an existing env file alone: for a
     * class-less stack, a file written by hand is the only way to provide a
     * value, and overwriting it with an empty one would wipe it silently.
     */
    protected function writeEnvironment(Stack $stack): void
    {
        if ($this->envFile->isHandWritten($stack)) {
            return;
        }

        $this->envFile->write($this->envPath($stack), $stack->environment(), $stack->name());
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    protected function fail(Stack $stack, string $step, Throwable $exception, ?Closure $output): RedeployResult
    {
        $this->events->dispatch(new StackRedeployFailedEvent($stack, $step, null, $exception));

        if ($output !== null) {
            $output('err', "Stack [{$stack->name()}] failed at the {$step} step: {$exception->getMessage()}".PHP_EOL);
        }

        return RedeployResult::Failed;
    }

    protected function upTimeout(Stack $stack): int
    {
        $timeout = $stack->timeout() ?? $this->docker->timeout('up', 120);
        $wait = $stack->wait();

        return $wait === null ? $timeout : max($timeout, $wait + 30);
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    protected function refreshLink(Stack $stack, ?Closure $output): void
    {
        if ($this->docker->isRemote($stack)) {
            return;
        }

        try {
            $this->link->refresh($stack);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            if ($output !== null) {
                $output('err', "Operator link for [{$stack->name()}] failed and was skipped: {$exception->getMessage()}".PHP_EOL);
            }
        }
    }
}
