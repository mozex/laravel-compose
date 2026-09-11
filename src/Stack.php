<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Mozex\Compose\Support\ComposeFile;
use Mozex\Compose\Support\StackStatus;
use ReflectionClass;

/**
 * One compose project owned by the app: a directory holding a compose file
 * and, usually, a subclass of this beside it. The class file marks the
 * location, so there is no path string to drift from where the compose file
 * sits. Everything else has a default read from the compose file itself.
 */
abstract class Stack
{
    protected ?ComposeFile $composeFile = null;

    /**
     * The values written to the stack's env file before every redeploy. Read
     * them from config so they trace back to the app's own environment.
     *
     * @return array<string, string|int|float|bool|null|\Stringable|\BackedEnum>
     */
    public function environment(): array
    {
        return [];
    }

    /**
     * The Compose project name. Defaults to the compose file's `name:` and
     * otherwise to the directory name, normalized to what Compose accepts.
     */
    public function name(): string
    {
        return static::normalizeName($this->compose()->name() ?? basename($this->directory()));
    }

    /**
     * The directory holding the compose file. Derived from where the class
     * file lives.
     */
    public function directory(): string
    {
        return dirname((string) (new ReflectionClass($this))->getFileName());
    }

    public function composePath(): string
    {
        return ComposeFile::find($this->directory()) ?? $this->directory().DIRECTORY_SEPARATOR.'docker-compose.yml';
    }

    public function compose(): ComposeFile
    {
        return $this->composeFile ??= ComposeFile::load($this->composePath());
    }

    /**
     * Fixed container names to force-remove before `up`. A container created
     * under another project context (a renamed directory, an older layout)
     * is invisible to this project's `up` and wedges it with a name conflict.
     *
     * @return list<string>
     */
    public function containerNames(): array
    {
        return $this->compose()->containerNames();
    }

    /**
     * Whether this host manages the stack. Return a config read here so
     * machines without Docker skip it.
     */
    public function enabled(): bool
    {
        return true;
    }

    /**
     * Compose profiles to activate on `up`.
     *
     * @return list<string>
     */
    public function profiles(): array
    {
        return [];
    }

    /**
     * Build images before `up`. Only for compose files with `build:` sections.
     */
    public function build(): bool
    {
        return false;
    }

    /**
     * Pull images before `up`. Keeps floating tags fresh; `up` alone never
     * refreshes a tag it already has locally.
     */
    public function pull(): bool
    {
        return true;
    }

    /**
     * Seconds to wait for every service to be running or healthy after `up`.
     * Null returns as soon as the containers are created.
     */
    public function wait(): ?int
    {
        return null;
    }

    /**
     * Timeout for `up`, in seconds. Null uses the configured default.
     */
    public function timeout(): ?int
    {
        return null;
    }

    /**
     * Timeout for `pull`, in seconds. Null uses the configured default.
     */
    public function pullTimeout(): ?int
    {
        return null;
    }

    /**
     * Timeout for `build`, in seconds. Null uses the configured default.
     */
    public function buildTimeout(): ?int
    {
        return null;
    }

    /**
     * Full path the stack directory is linked to for the operator. Null derives
     * it from the configured link directory; an empty string disables the link
     * for this stack.
     */
    public function linkPath(): ?string
    {
        return null;
    }

    /**
     * DOCKER_HOST for this stack. Null uses the configured default.
     */
    public function host(): ?string
    {
        return null;
    }

    /**
     * Docker context for this stack. Null uses the configured default.
     */
    public function context(): ?string
    {
        return null;
    }

    public function status(): StackStatus
    {
        return $this->docker()->status($this);
    }

    public function isRunning(): bool
    {
        return $this->status()->isRunning();
    }

    public function isHealthy(): bool
    {
        return $this->status()->isHealthy();
    }

    public function logs(?string $service = null, int $tail = 100): string
    {
        return $this->docker()->logs($this, $service, $tail);
    }

    /**
     * @param  list<string>  $command
     */
    public function exec(string $service, array $command, int $timeout = 60): ProcessResult
    {
        return $this->docker()->exec($this, $service, $command, $timeout);
    }

    public function down(bool $volumes = false): ProcessResult
    {
        return $this->docker()->down($this, $volumes);
    }

    public static function normalizeName(string $name): string
    {
        $normalized = (string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($name)));

        return rtrim((string) preg_replace('/^[^a-z0-9]+/', '', $normalized), '-');
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) === 1;
    }

    protected function docker(): Docker
    {
        return Container::getInstance()->make(Docker::class);
    }
}
