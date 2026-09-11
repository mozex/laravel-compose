<?php

declare(strict_types=1);

namespace Mozex\Compose\Health;

use Illuminate\Container\Container;
use Mozex\Compose\Compose;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * A spatie/laravel-health check: fails when an enabled stack is not running,
 * warns when one runs but reports unhealthy. Register it like any other
 * check: `Health::checks([StacksCheck::new()])`.
 */
class StacksCheck extends Check
{
    public function run(): Result
    {
        $compose = Container::getInstance()->make(Compose::class);
        $result = Result::make();

        if (! $compose->enabled()) {
            return $result->ok('Compose is disabled on this host.');
        }

        $meta = [];
        $down = [];
        $unhealthy = [];

        foreach ($compose->stacks() as $name => $stack) {
            if (! $stack->enabled()) {
                $meta[$name] = 'disabled';

                continue;
            }

            $status = $stack->status();

            $meta[$name] = match (true) {
                $status->isEmpty() => 'not created',
                ! $status->isRunning() => 'down',
                ! $status->isHealthy() => 'unhealthy',
                default => 'healthy',
            };

            if (! $status->isRunning()) {
                $down[] = $name;

                continue;
            }

            if (! $status->isHealthy()) {
                $unhealthy[] = $name;
            }
        }

        $result->meta($meta);

        if ($down !== []) {
            return $result->failed('Stacks not running: '.implode(', ', $down).'.');
        }

        if ($unhealthy !== []) {
            return $result->warning('Stacks unhealthy: '.implode(', ', $unhealthy).'.');
        }

        return $result->ok($meta === [] ? 'No stacks are registered.' : 'Every enabled stack is running.');
    }
}
