<?php

declare(strict_types=1);

namespace Mozex\Compose\Doctor;

class Report
{
    /**
     * @param  list<Problem>  $problems
     */
    public function __construct(public readonly array $problems) {}

    /**
     * @return list<Problem>
     */
    public function errors(): array
    {
        return $this->ofSeverity(Severity::Error);
    }

    /**
     * @return list<Problem>
     */
    public function warnings(): array
    {
        return $this->ofSeverity(Severity::Warning);
    }

    /**
     * @return list<Problem>
     */
    public function notes(): array
    {
        return $this->ofSeverity(Severity::Info);
    }

    /**
     * @return list<Problem>
     */
    public function forStack(string $stack): array
    {
        return array_values(array_filter($this->problems, fn (Problem $problem): bool => $problem->stack === $stack));
    }

    /**
     * No errors. Warnings do not make a report unclean.
     */
    public function isClean(): bool
    {
        return $this->errors() === [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings() !== [];
    }

    /**
     * Every error and warning as one line each, for assertion messages.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(
            fn (Problem $problem): string => $problem->severity->value.': '.$problem->describe(),
            [...$this->errors(), ...$this->warnings()],
        );
    }

    /**
     * @return list<Problem>
     */
    protected function ofSeverity(Severity $severity): array
    {
        return array_values(array_filter($this->problems, fn (Problem $problem): bool => $problem->severity === $severity));
    }
}
