<?php

declare(strict_types=1);

namespace Mozex\Compose\Events;

use Illuminate\Contracts\Process\ProcessResult;
use Mozex\Compose\Stack;
use Throwable;

class StackRedeployFailedEvent
{
    /**
     * @param  string  $step  The redeploy step that failed: env, build, or up
     * @param  ProcessResult|null  $result  The failed process, for build and up
     * @param  Throwable|null  $exception  What went wrong writing the env file, for env
     */
    public function __construct(
        public readonly Stack $stack,
        public readonly string $step,
        public readonly ?ProcessResult $result = null,
        public readonly ?Throwable $exception = null,
    ) {}

    /**
     * The reason in one string: the exception message, or what the process
     * wrote to stderr (falling back to stdout).
     */
    public function reason(): string
    {
        if ($this->exception !== null) {
            return $this->exception->getMessage();
        }

        if ($this->result === null) {
            return '';
        }

        $error = trim($this->result->errorOutput());

        return $error !== '' ? $error : trim($this->result->output());
    }
}
