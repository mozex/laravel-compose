<?php

declare(strict_types=1);

namespace Mozex\Compose\Commands;

use Illuminate\Console\Command;
use Mozex\Compose\Compose;
use Mozex\Compose\Exceptions\ComposeException;

class StatusCommand extends Command
{
    protected $signature = 'compose:status {stack? : Show only the stack with this name}';

    protected $description = 'Show the containers of every Docker Compose stack this app owns';

    public function handle(Compose $compose): int
    {
        /** @var string|null $only */
        $only = $this->argument('stack');

        try {
            $stacks = $only === null ? $compose->stacks() : [$only => $compose->stack($only)];
        } catch (ComposeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($stacks === []) {
            $this->components->info('No stacks are registered.');

            return self::SUCCESS;
        }

        $rows = [];
        $allRunning = true;

        foreach ($stacks as $name => $stack) {
            $enabled = $stack->enabled();

            // A disabled stack is not asked about: on a host without Docker the
            // question itself would fail.
            if (! $enabled && $only === null) {
                $rows[] = [$name, '-', '-', 'disabled', '-', '-'];

                continue;
            }

            $status = $stack->status();

            if ($status->isEmpty()) {
                $rows[] = [$name, '-', '-', $enabled ? 'not created' : 'disabled', '-', '-'];
                $allRunning = $allRunning && ! $enabled;

                continue;
            }

            foreach ($status->containers as $container) {
                $rows[] = [
                    $name,
                    $container->service,
                    $container->name,
                    $container->state,
                    $container->health ?? '-',
                    $container->ports === [] ? '-' : implode(', ', $container->ports),
                ];
            }

            $allRunning = $allRunning && (! $enabled || $status->isRunning());
        }

        $this->table(['Stack', 'Service', 'Container', 'State', 'Health', 'Ports'], $rows);

        return $allRunning ? self::SUCCESS : self::FAILURE;
    }
}
