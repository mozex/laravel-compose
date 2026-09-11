<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

class StackStatus
{
    /**
     * @param  list<ContainerStatus>  $containers
     */
    public function __construct(
        public readonly string $stack,
        public readonly array $containers,
    ) {}

    /**
     * Parses `docker compose ps --all --format json`, which prints one JSON
     * object per line on current releases and a JSON array on older ones.
     */
    public static function fromJson(string $stack, string $json): self
    {
        $json = trim($json);

        if ($json === '') {
            return new self($stack, []);
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $decoded = [];

            foreach (preg_split('/\r?\n/', $json) ?: [] as $line) {
                $row = json_decode(trim($line), true);

                if (is_array($row)) {
                    $decoded[] = $row;
                }
            }
        }

        if (array_is_list($decoded) === false) {
            $decoded = [$decoded];
        }

        $containers = [];

        foreach ($decoded as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $containers[] = ContainerStatus::fromRow($row);
            }
        }

        return new self($stack, $containers);
    }

    public function isEmpty(): bool
    {
        return $this->containers === [];
    }

    /**
     * Every container is running, or finished cleanly as a one-shot.
     */
    public function isRunning(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        foreach ($this->containers as $container) {
            if (! $container->isRunning() && ! $container->isFinished()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Running, and every container with a healthcheck reports healthy.
     */
    public function isHealthy(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        foreach ($this->containers as $container) {
            if (! $container->isHealthy()) {
                return false;
            }
        }

        return true;
    }
}
