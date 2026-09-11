<?php

declare(strict_types=1);

namespace Mozex\Compose;

/**
 * A stack found by directory discovery with no Stack class beside its
 * compose file. It has an empty environment, so the compose file's own
 * `${VAR:-default}` values carry every knob.
 */
class DiscoveredStack extends Stack
{
    public function __construct(protected string $stackDirectory) {}

    public function directory(): string
    {
        return $this->stackDirectory;
    }
}
