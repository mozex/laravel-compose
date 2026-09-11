<?php

declare(strict_types=1);

namespace Mozex\Compose\Commands;

use Illuminate\Console\Command;
use Mozex\Compose\Compose;
use Mozex\Compose\Docker;
use Mozex\Compose\Exceptions\ComposeException;

class LogsCommand extends Command
{
    protected $signature = 'compose:logs
        {stack : The stack to read logs from}
        {--service= : Only this service}
        {--tail=100 : Number of lines to show from the end of each log}
        {--follow : Keep streaming new output}';

    protected $description = 'Show the logs of a Docker Compose stack this app owns';

    public function handle(Compose $compose, Docker $docker): int
    {
        try {
            /** @var string $name */
            $name = $this->argument('stack');
            $stack = $compose->stack($name);
        } catch (ComposeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $arguments = ['logs', '--no-color', '--tail', (string) max(0, (int) $this->option('tail'))];

        if ($this->option('follow')) {
            $arguments[] = '--follow';
        }

        /** @var string|null $service */
        $service = $this->option('service');

        if ($service !== null && $service !== '') {
            $arguments[] = $service;
        }

        if ($this->option('follow')) {
            $result = $docker->compose($stack, $arguments, 0, fn (string $type, string $buffer) => $this->output->write($buffer));

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        }

        $result = $docker->compose($stack, $arguments, 60);

        $this->output->write($result->output());
        $this->output->write($result->errorOutput());

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
