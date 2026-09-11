<?php

declare(strict_types=1);

namespace Mozex\Compose\Testing;

use Closure;
use Mozex\Compose\Compose;
use Mozex\Compose\Enums\RedeployResult;
use PHPUnit\Framework\Assert;

/**
 * Stands in for the manager in tests. Redeploys are recorded instead of run:
 * an enabled stack records Redeployed, a disabled one Skipped, and no docker
 * command or env file is ever touched.
 */
class ComposeFake extends Compose
{
    /**
     * @var array<string, RedeployResult>
     */
    protected array $results = [];

    /**
     * @var array<string, RedeployResult>
     */
    protected array $forced = [];

    /**
     * Make the next redeploy of a stack report the given result.
     */
    public function shouldFail(string $stack): static
    {
        $this->forced[$stack] = RedeployResult::Failed;

        return $this;
    }

    public function redeploy(?string $only = null, ?Closure $output = null): array
    {
        $stacks = $only === null ? $this->stacks() : [$only => $this->stack($only)];
        $results = [];

        foreach ($stacks as $name => $stack) {
            $results[$name] = match (true) {
                isset($this->forced[$name]) => $this->forced[$name],
                ! $this->enabled(), ! $stack->enabled() => RedeployResult::Skipped,
                default => RedeployResult::Redeployed,
            };

            unset($this->forced[$name]);
            $this->results[$name] = $results[$name];
        }

        return $results;
    }

    /**
     * @return array<string, RedeployResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function assertRedeployed(string $stack): static
    {
        Assert::assertSame(
            RedeployResult::Redeployed,
            $this->results[$stack] ?? null,
            "Expected stack [{$stack}] to be redeployed, but it was ".$this->describe($stack).'.',
        );

        return $this;
    }

    public function assertSkipped(string $stack): static
    {
        Assert::assertSame(
            RedeployResult::Skipped,
            $this->results[$stack] ?? null,
            "Expected stack [{$stack}] to be skipped, but it was ".$this->describe($stack).'.',
        );

        return $this;
    }

    public function assertNotRedeployed(string $stack): static
    {
        Assert::assertNotSame(
            RedeployResult::Redeployed,
            $this->results[$stack] ?? null,
            "Expected stack [{$stack}] not to be redeployed, but it was.",
        );

        return $this;
    }

    public function assertNothingRedeployed(): static
    {
        $redeployed = array_keys(array_filter($this->results, fn (RedeployResult $result): bool => $result === RedeployResult::Redeployed));

        Assert::assertSame([], $redeployed, 'Expected no stack to be redeployed, but these were: '.implode(', ', $redeployed).'.');

        return $this;
    }

    protected function describe(string $stack): string
    {
        $result = $this->results[$stack] ?? null;

        return $result === null ? 'never redeployed' : $result->value;
    }
}
