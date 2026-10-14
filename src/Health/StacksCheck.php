<?php

declare(strict_types=1);

namespace Mozex\Compose\Health;

use Illuminate\Container\Container;
use Mozex\Compose\Compose;
use Mozex\Compose\Exceptions\ComposeException;
use Mozex\Compose\Stack;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * A spatie/laravel-health check: fails when an enabled stack is not running,
 * or runs without the ports its compose file publishes; warns when one runs
 * but reports unhealthy. Register it like any other check:
 * `Health::checks([StacksCheck::new()])`.
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
        $unpublished = [];
        $unhealthy = [];

        foreach ($compose->stacks() as $name => $stack) {
            if (! $stack->enabled()) {
                $meta[$name] = 'disabled';

                continue;
            }

            $status = $stack->status();
            $withoutPorts = $status->isRunning() ? $status->withoutPorts($this->publishingServices($stack)) : [];

            $meta[$name] = match (true) {
                $status->isEmpty() => 'not created',
                ! $status->isRunning() => 'down',
                $withoutPorts !== [] => 'no ports',
                ! $status->isHealthy() => 'unhealthy',
                default => 'healthy',
            };

            if (! $status->isRunning()) {
                $down[] = $name;

                continue;
            }

            if ($withoutPorts !== []) {
                $unpublished[] = $name.' ('.implode(', ', $withoutPorts).')';

                continue;
            }

            if (! $status->isHealthy()) {
                $unhealthy[] = $name;
            }
        }

        $result->meta($meta);

        $failures = array_filter([
            $down === [] ? null : 'Stacks not running: '.implode(', ', $down).'.',
            $unpublished === [] ? null : 'Stacks running without their published ports: '.implode(', ', $unpublished).'.',
        ]);

        if ($failures !== []) {
            return $result->failed(implode(' ', $failures));
        }

        if ($unhealthy !== []) {
            return $result->warning('Stacks unhealthy: '.implode(', ', $unhealthy).'.');
        }

        return $result->ok($meta === [] ? 'No stacks are registered.' : 'Every enabled stack is running.');
    }

    /**
     * A compose file that cannot be read declares nothing here; the doctor
     * and the redeploy both report it themselves.
     *
     * @return list<string>
     */
    protected function publishingServices(Stack $stack): array
    {
        try {
            return $stack->compose()->publishingServices();
        } catch (ComposeException) {
            return [];
        }
    }
}
