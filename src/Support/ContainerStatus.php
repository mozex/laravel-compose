<?php

declare(strict_types=1);

namespace Mozex\Compose\Support;

class ContainerStatus
{
    /**
     * @param  list<string>  $ports
     */
    public function __construct(
        public readonly string $name,
        public readonly string $service,
        public readonly string $state,
        public readonly ?string $health,
        public readonly string $status,
        public readonly int $exitCode,
        public readonly array $ports = [],
        public readonly bool $oneOff = false,
    ) {}

    /**
     * @param  array<string, mixed>  $row  One entry of `docker compose ps --format json`
     */
    public static function fromRow(array $row): self
    {
        $ports = [];

        foreach (is_array($row['Publishers'] ?? null) ? $row['Publishers'] : [] as $publisher) {
            if (! is_array($publisher)) {
                continue;
            }

            $published = (int) ($publisher['PublishedPort'] ?? 0);

            if ($published === 0) {
                continue;
            }

            $url = is_string($publisher['URL'] ?? null) ? $publisher['URL'] : '';
            $target = (int) ($publisher['TargetPort'] ?? 0);
            $protocol = is_string($publisher['Protocol'] ?? null) ? $publisher['Protocol'] : 'tcp';

            $ports[] = ($url === '' ? '' : $url.':').$published.'->'.$target.'/'.$protocol;
        }

        $health = $row['Health'] ?? null;

        return new self(
            name: is_string($row['Name'] ?? null) ? $row['Name'] : '',
            service: is_string($row['Service'] ?? null) ? $row['Service'] : '',
            state: is_string($row['State'] ?? null) ? $row['State'] : 'unknown',
            health: is_string($health) && $health !== '' ? $health : null,
            status: is_string($row['Status'] ?? null) ? $row['Status'] : '',
            exitCode: (int) ($row['ExitCode'] ?? 0),
            ports: array_values(array_unique($ports)),
            oneOff: self::isOneOff($row['Labels'] ?? null),
        );
    }

    /**
     * A `compose run` container, which `ps --all` lists under the service it
     * runs and which publishes nothing unless run with `--service-ports`.
     * Compose prints labels as one comma-joined string; releases before 2.21
     * print none, so a run container there reads as an ordinary one.
     */
    protected static function isOneOff(mixed $labels): bool
    {
        if (is_array($labels)) {
            return strcasecmp((string) ($labels['com.docker.compose.oneoff'] ?? ''), 'true') === 0;
        }

        return is_string($labels) && preg_match('/(^|,)com\.docker\.compose\.oneoff=true(,|$)/i', $labels) === 1;
    }

    public function isRunning(): bool
    {
        return $this->state === 'running';
    }

    /**
     * A one-shot service that finished cleanly counts as done, not dead.
     */
    public function isFinished(): bool
    {
        return $this->state === 'exited' && $this->exitCode === 0;
    }

    public function isHealthy(): bool
    {
        return $this->health === null || $this->health === 'healthy';
    }
}
