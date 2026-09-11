<?php

declare(strict_types=1);

namespace Mozex\Compose\Events;

use Mozex\Compose\Stack;

class StackRedeployedEvent
{
    public function __construct(public readonly Stack $stack) {}
}
