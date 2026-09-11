<?php

declare(strict_types=1);

namespace Mozex\Compose\Enums;

enum RedeployResult: string
{
    case Redeployed = 'redeployed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }
}
