<?php

declare(strict_types=1);

namespace Mozex\Compose\Events;

use Illuminate\Contracts\Process\ProcessResult;
use Mozex\Compose\Stack;

class StackRedeployFailedEvent
{
    /**
     * @param  string  $step  The redeploy step that failed: build or up
     */
    public function __construct(
        public readonly Stack $stack,
        public readonly string $step,
        public readonly ProcessResult $result,
    ) {}
}
