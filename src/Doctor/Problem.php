<?php

declare(strict_types=1);

namespace Mozex\Compose\Doctor;

class Problem
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?string $stack = null,
    ) {}

    public static function error(string $message, ?string $stack = null): self
    {
        return new self(Severity::Error, $message, $stack);
    }

    public static function warning(string $message, ?string $stack = null): self
    {
        return new self(Severity::Warning, $message, $stack);
    }

    public static function info(string $message, ?string $stack = null): self
    {
        return new self(Severity::Info, $message, $stack);
    }

    public function describe(): string
    {
        return ($this->stack === null ? '' : "[{$this->stack}] ").$this->message;
    }
}
