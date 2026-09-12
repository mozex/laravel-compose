---
title: Testing
weight: 4
---

## Faking the manager

`Compose::fake()` swaps the manager for one that records redeploys instead of running them. An enabled stack records `Redeployed`, a disabled one `Skipped`, and no env file is written and no docker command runs. It also calls `Process::fake()`, so a stack's `status()`, `logs()`, or `exec()` never reach a daemon either. When a test needs specific process results as well, call `Process::fake([...])` before `Compose::fake()`: an existing fake is kept, but a bare one installed first would shadow patterns you add afterwards.

```php
use Mozex\Compose\Facades\Compose;

it('redeploys the search stack after a deploy', function (): void {
    $fake = Compose::fake();

    $this->artisan('deploy:after')->assertSuccessful();

    $fake->assertRedeployed('meilisearch')
        ->assertSkipped('mailpit');
});
```

Assertions: `assertRedeployed($name)`, `assertSkipped($name)`, `assertNotRedeployed($name)`, `assertNothingRedeployed()`. `results()` returns the recorded `RedeployResult` per stack. `shouldFail($name)` makes the next redeploy of that stack report `Failed`, for testing the code that reacts to one.

## Faking the processes only

When you want the real recipe to run against a fake daemon, use `Process::fake()` directly. Every docker invocation goes through Laravel's `Process` facade with an array command, so `assertRan` sees the exact arguments:

```php
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

Process::fake();

Compose::redeploy('meilisearch');

Process::assertRan(fn (PendingProcess $process): bool => in_array('up', $process->command, true)
    && in_array('--wait', $process->command, true));
```

One thing to know about fake patterns: Symfony quotes every argument on Linux, so a pattern like `'*ps --format*'` matches on Windows and not on CI. Put `*` between tokens (`'*ps*--format*'`) or match on `$process->command` in a closure.

## Testing a stack class

A stack class is plain PHP. Test its `environment()` and `enabled()` against config the way you'd test anything that reads config:

```php
it('derives the port from the scout host', function (): void {
    config()->set('scout.meilisearch.host', 'http://127.0.0.1:7701');

    expect((new MeilisearchStack)->environment()['MEILISEARCH_PORT'])->toBe(7701);
});
```

And keep the stacks consistent with their compose files with the [doctor](../deploying/doctor.md) report in an architecture test.
