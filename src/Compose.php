<?php

declare(strict_types=1);

namespace Mozex\Compose;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Mozex\Compose\Actions\RedeployStack;
use Mozex\Compose\Doctor\Report;
use Mozex\Compose\Doctor\Validator;
use Mozex\Compose\Enums\RedeployResult;

class Compose
{
    public function __construct(
        protected StackRegistry $registry,
        protected Container $container,
        protected Repository $config,
    ) {}

    /**
     * @return array<string, Stack> Keyed by stack name
     */
    public function stacks(): array
    {
        return $this->registry->all();
    }

    public function stack(string $name): Stack
    {
        return $this->registry->get($name);
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    /**
     * @param  Stack|class-string<Stack>  $stack
     */
    public function register(Stack|string $stack): static
    {
        $this->registry->register($stack);

        return $this;
    }

    /**
     * The master switch from config. Individual stacks add their own guard.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('compose.enabled', true);
    }

    /**
     * Redeploy every stack, or only the named one.
     *
     * @param  (Closure(string, string): void)|null  $output  Receives the process output type and buffer
     * @return array<string, RedeployResult> Keyed by stack name
     */
    public function redeploy(?string $only = null, ?Closure $output = null): array
    {
        $stacks = $only === null ? $this->stacks() : [$only => $this->stack($only)];
        $action = $this->container->make(RedeployStack::class);
        $results = [];

        foreach ($stacks as $name => $stack) {
            $results[$name] = $action->execute($stack, $output);
        }

        return $results;
    }

    /**
     * Run the same checks as `compose:doctor` and get the report back, so a
     * test suite can assert the stacks are consistent with their compose files.
     *
     * @param  bool  $withDaemon  Also run the checks that talk to the docker binary and daemon
     */
    public function validate(bool $withDaemon = true): Report
    {
        return $this->container->make(Validator::class)->run($withDaemon);
    }

    public function docker(): Docker
    {
        return $this->container->make(Docker::class);
    }
}
