<?php

declare(strict_types=1);

namespace Mozex\Compose\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Testing\ComposeFake;

/**
 * @method static array<string, \Mozex\Compose\Stack> stacks()
 * @method static \Mozex\Compose\Stack stack(string $name)
 * @method static bool has(string $name)
 * @method static \Mozex\Compose\Compose register(\Mozex\Compose\Stack|string $stack)
 * @method static bool enabled()
 * @method static array<string, \Mozex\Compose\Enums\RedeployResult> redeploy(?string $only = null, ?\Closure $output = null)
 * @method static \Mozex\Compose\Doctor\Report validate(bool $withDaemon = true)
 * @method static \Mozex\Compose\Docker docker()
 *
 * @see \Mozex\Compose\Compose
 */
class Compose extends Facade
{
    /**
     * Swap in a fake that records redeploys instead of running docker. The
     * Process facade is faked too, so a stack's status, logs, and exec calls
     * never reach a daemon either. A Process fake that is already in place
     * is kept, so call Process::fake([...]) first when specific results are
     * needed: a bare fake installed here would shadow patterns added later.
     */
    public static function fake(): ComposeFake
    {
        if (! Process::isRecording()) {
            Process::fake();
        }

        $fake = static::$app->make(ComposeFake::class);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Mozex\Compose\Compose::class;
    }
}
