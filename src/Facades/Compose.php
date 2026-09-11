<?php

declare(strict_types=1);

namespace Mozex\Compose\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Mozex\Compose\Compose
 */
class Compose extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Mozex\Compose\Compose::class;
    }
}
