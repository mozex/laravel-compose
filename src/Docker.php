<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Support\StackStatus;

/**
 * The one place that runs the docker binary. Every command goes through the
 * Process facade, so Process::fake() sees all of it, and every compose call
 * names its project and directory explicitly so two stacks can never collide
 * on a directory basename.
 */
class Docker
{
    public function __construct(protected Repository $config) {}

    public function binary(): string
    {
        $binary = $this->config->get('compose.docker.binary', 'docker');

        return is_string($binary) && $binary !== '' ? $binary : 'docker';
    }

    public function host(?Stack $stack = null): ?string
    {
        return $this->blankToNull($stack?->host()) ?? $this->blankToNull($this->config->get('compose.docker.host'));
    }

    public function context(?Stack $stack = null): ?string
    {
        return $this->blankToNull($stack?->context()) ?? $this->blankToNull($this->config->get('compose.docker.context'));
    }

    /**
     * Whether commands for this stack reach a daemon on another machine.
     */
    public function isRemote(?Stack $stack = null): bool
    {
        $host = $this->host($stack);

        if ($host !== null && ! str_starts_with($host, 'unix://') && ! str_starts_with($host, 'npipe://')) {
            return true;
        }

        return $this->context($stack) !== null;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function command(array $arguments, ?Stack $stack = null): array
    {
        $command = [$this->binary()];
        $context = $this->context($stack);

        if ($context !== null) {
            $command[] = '--context';
            $command[] = $context;
        }

        return [...$command, ...$arguments];
    }

    /**
     * @return array<string, string>
     */
    public function environment(?Stack $stack = null): array
    {
        $host = $this->host($stack);

        return $host === null ? [] : ['DOCKER_HOST' => $host];
    }

    /**
     * A process that runs past its timeout comes back as a failed result, the
     * same shape as any other failure, so callers decide what a stalled pull
     * or a hung `up` means instead of the exception ending the whole run.
     *
     * @param  list<string>  $arguments
     * @param  int  $timeout  Seconds, or 0 to let the process run until it exits
     */
    public function run(array $arguments, ?Stack $stack = null, ?string $path = null, int $timeout = 60, ?Closure $output = null): ProcessResult
    {
        $process = ($timeout > 0 ? Process::timeout($timeout) : Process::forever())->env($this->environment($stack));

        if ($path !== null) {
            $process = $process->path($path);
        }

        try {
            return $process->run($this->command($arguments, $stack), $output);
        } catch (ProcessTimedOutException $exception) {
            return $exception->result;
        }
    }

    /**
     * Run a `docker compose` subcommand for a stack, from its directory.
     *
     * @param  list<string>  $arguments
     */
    public function compose(Stack $stack, array $arguments, int $timeout = 60, ?Closure $output = null): ProcessResult
    {
        return $this->run($this->composeArguments($stack, $arguments), $stack, $stack->directory(), $timeout, $output);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function composeArguments(Stack $stack, array $arguments): array
    {
        $command = [
            'compose',
            '--project-name', $stack->name(),
            '--project-directory', $stack->directory(),
            '--file', $stack->composePath(),
        ];

        foreach ($stack->profiles() as $profile) {
            $command[] = '--profile';
            $command[] = $profile;
        }

        return [...$command, ...$arguments];
    }

    public function status(Stack $stack): StackStatus
    {
        $result = $this->compose($stack, ['ps', '--all', '--format', 'json'], 30);

        return StackStatus::fromJson($stack->name(), $result->successful() ? $result->output() : '');
    }

    /**
     * @param  int|string  $tail  A number of lines, or `all`
     */
    public function logs(Stack $stack, ?string $service = null, int|string $tail = 100, ?Closure $output = null): string
    {
        $arguments = ['logs', '--no-color', '--tail', $tail === 'all' ? 'all' : (string) max(0, (int) $tail)];

        if ($service !== null) {
            $arguments[] = $service;
        }

        $result = $this->compose($stack, $arguments, 60, $output);

        return $result->output().$result->errorOutput();
    }

    /**
     * @param  list<string>  $command
     */
    public function exec(Stack $stack, string $service, array $command, int $timeout = 60): ProcessResult
    {
        return $this->compose($stack, ['exec', '--no-TTY', $service, ...$command], $timeout);
    }

    public function down(Stack $stack, bool $volumes = false, ?Closure $output = null): ProcessResult
    {
        $arguments = ['down', '--remove-orphans'];

        if ($volumes) {
            $arguments[] = '--volumes';
        }

        return $this->compose($stack, $arguments, $this->timeout('down', 60), $output);
    }

    public function timeout(string $step, int $default): int
    {
        $timeout = $this->config->get("compose.timeouts.{$step}", $default);

        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : $default;
    }

    protected function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
