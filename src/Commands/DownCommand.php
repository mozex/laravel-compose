<?php

declare(strict_types=1);

namespace Mozex\Compose\Commands;

use Illuminate\Console\Command;
use Mozex\Compose\Compose;
use Mozex\Compose\Docker;
use Mozex\Compose\Exceptions\ComposeException;

class DownCommand extends Command
{
    protected $signature = 'compose:down
        {stack? : Stop only the stack with this name}
        {--volumes : Also remove the named volumes, which deletes their data}
        {--force : Skip the confirmation when removing volumes}';

    protected $description = 'Stop and remove the containers of the Docker Compose stacks this app owns';

    public function handle(Compose $compose, Docker $docker): int
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

        $volumes = (bool) $this->option('volumes');

        if ($volumes && ! $this->option('force') && ! $this->confirm('This also removes the named volumes and the data inside them. Continue?')) {
            $this->components->warn('Nothing was stopped.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($stacks as $name => $stack) {
            $result = $docker->down($stack, $volumes, fn (string $type, string $buffer) => $this->output->write($buffer));

            if ($result->failed()) {
                $this->components->error("Stack [{$name}] failed to stop.");
                $failed = true;

                continue;
            }

            $this->components->info("Stack [{$name}] stopped.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
