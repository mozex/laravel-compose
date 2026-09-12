<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Support\EnvFile;
use Mozex\Compose\Support\StackStatus;

/**
 * The one place that runs the docker binary. Every command goes through the
 * Process facade, so Process::fake() sees all of it, and every compose call
 * names its project and directory explicitly so two stacks can never collide
 * on a directory basename.
 */
class Docker
{
    public function __construct(
        protected Repository $config,
        protected EnvFile $envFile,
    ) {}

    public function binary(): string
    {
        $binary = $this->config->get('compose.docker.binary', 'docker');

        return is_string($binary) && $binary !== '' ? $binary : 'docker';
    }

    public function host(?Stack $stack = null): ?string
    {
        return $this->target($stack)['host'];
    }

    public function context(?Stack $stack = null): ?string
    {
        return $this->target($stack)['context'];
    }

    /**
     * Whether commands for this stack reach a daemon on another machine: a
     * named context, or a host that is not a local socket.
     */
    public function isRemote(?Stack $stack = null): bool
    {
        $target = $this->target($stack);

        if ($target['context'] !== null) {
            return true;
        }

        $host = $target['host'];

        return $host !== null && ! str_starts_with($host, 'unix://') && ! str_starts_with($host, 'npipe://');
    }

    /**
     * The daemon a stack's commands go to. A stack's own host() or context()
     * replaces both global values, never one of them. At either level a
     * context beside a host wins, which is Docker's own rule (`--context`
     * overrides DOCKER_HOST), so the host is dropped rather than passed and
     * ignored. The `default` context is the local daemon, the same as none.
     *
     * @return array{host: string|null, context: string|null}
     */
    protected function target(?Stack $stack): array
    {
        $host = $this->blankToNull($stack?->host());
        $context = $this->blankToNull($stack?->context());

        if ($host === null && $context === null) {
            $host = $this->blankToNull($this->config->get('compose.docker.host'));
            $context = $this->blankToNull($this->config->get('compose.docker.context'));
        }

        if ($context === 'default') {
            $context = null;
        }

        return ['host' => $context === null ? $host : null, 'context' => $context];
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
     * @param  array<string, string|false>  $environment  Extra variables for the process; false unsets one
     */
    public function run(array $arguments, ?Stack $stack = null, ?string $path = null, int $timeout = 60, ?Closure $output = null, array $environment = []): ProcessResult
    {
        $process = ($timeout > 0 ? Process::timeout($timeout) : Process::forever())->env([...$environment, ...$this->environment($stack)]);

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
        return $this->run($this->composeArguments($stack, $arguments), $stack, $stack->directory(), $timeout, $output, $this->shadowedKeys($stack));
    }

    /**
     * Compose reads the shell before the env file, and Laravel puts the app's
     * own .env into the shell of every child process. An app key that shares
     * a name with a stack key (MEILISEARCH_PORT in both, say) would silently
     * beat the file the redeploy just wrote. Unsetting every key the stack
     * writes, for the compose process only, makes the file the value compose
     * sees.
     *
     * @return array<string, false>
     */
    protected function shadowedKeys(Stack $stack): array
    {
        return array_fill_keys(array_keys($this->envFile->valuesFor($stack)), false);
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

        // Compose reads {project-directory}/.env on its own. A file under any
        // other name has to be named, or every ${VAR} in the file resolves empty.
        $envFile = $this->envFile->pathFor($stack);

        if ($this->envFile->name() !== '.env' && is_file($envFile)) {
            $command[] = '--env-file';
            $command[] = $envFile;
        }

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
        if (is_string($tail) && $tail !== 'all' && preg_match('/^\d+$/', $tail) !== 1) {
            throw ComposeException::invalidLogTail($tail);
        }

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
