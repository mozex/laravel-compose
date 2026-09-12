<?php

declare(strict_types=1);

namespace Mozex\Compose\Commands;

use Illuminate\Console\Command;
use Mozex\Compose\Actions\RedeployStack;
use Mozex\Compose\Compose;
use Mozex\Compose\Docker;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Throwable;

class RedeployCommand extends Command
{
    protected $signature = 'compose:redeploy
        {stack? : Redeploy only the stack with this name}
        {--dry-run : Print what would run without touching docker}';

    protected $description = 'Regenerate env files and recreate the Docker Compose stacks this app owns';

    public function handle(Compose $compose, RedeployStack $action, Docker $docker): int
    {
        /** @var string|null $only */
        $only = $this->argument('stack');

        try {
            if ($this->option('dry-run')) {
                return $this->dryRun($compose, $action, $docker, $only);
            }

            $results = $compose->redeploy($only, fn (string $type, string $buffer) => $this->output->write($buffer));
        } catch (ComposeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->components->info('No stacks are registered.');

            return self::SUCCESS;
        }

        foreach ($results as $name => $result) {
            match ($result) {
                RedeployResult::Redeployed => $this->components->info("Stack [{$name}] redeployed."),
                RedeployResult::Skipped => $this->components->warn("Stack [{$name}] is not enabled on this host. Skipped."),
                RedeployResult::Failed => $this->components->error("Stack [{$name}] failed to redeploy."),
            };
        }

        return in_array(RedeployResult::Failed, $results, true) ? self::FAILURE : self::SUCCESS;
    }

    protected function dryRun(Compose $compose, RedeployStack $action, Docker $docker, ?string $only): int
    {
        $stacks = $only === null ? $compose->stacks() : [$only => $compose->stack($only)];

        if ($stacks === []) {
            $this->components->info('No stacks are registered.');

            return self::SUCCESS;
        }

        foreach ($stacks as $name => $stack) {
            $this->components->twoColumnDetail("<fg=green>{$name}</>", $stack->directory());

            if (! $compose->enabled() || ! $stack->enabled()) {
                $this->line('  skipped: not enabled on this host');

                continue;
            }

            try {
                $keys = array_keys($stack->environment());
                $this->line('  env file: '.$action->envPath($stack).($keys === [] ? ' (empty)' : ' with '.implode(', ', $keys)));

                foreach ($this->plannedCommands($stack, $action, $docker) as $command) {
                    $this->line('  $ '.$command);
                }
            } catch (Throwable $exception) {
                // The real run fails this stack and moves on, whether the
                // compose file is unreadable or environment() throws; the plan says so.
                $this->line('  would fail: '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function plannedCommands(Stack $stack, RedeployStack $action, Docker $docker): array
    {
        $commands = [];

        if ($stack->build()) {
            $commands[] = $docker->command($docker->composeArguments($stack, ['build', '--pull']), $stack);
        }

        if ($stack->pull()) {
            $commands[] = $docker->command($docker->composeArguments($stack, ['pull', '--ignore-buildable', '--quiet']), $stack);
        }

        if ($stack->containerNames() !== []) {
            $commands[] = $docker->command(['rm', '-f', ...$stack->containerNames()], $stack);
        }

        $commands[] = $docker->command($docker->composeArguments($stack, $action->upArguments($stack)), $stack);

        return array_map(fn (array $command): string => implode(' ', $command), $commands);
    }
}
