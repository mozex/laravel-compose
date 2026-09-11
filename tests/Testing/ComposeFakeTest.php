<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Compose\Enums\RedeployResult;
use Mozex\Compose\Facades\Compose;
use Mozex\Compose\Testing\ComposeFake;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    config()->set('compose.stacks', []);
    config()->set('compose.discover', []);
});

it('records redeploys instead of running docker', function (): void {
    $fake = Compose::fake();
    $on = fakeStack(['name' => 'on']);
    $off = fakeStack(['name' => 'off', 'enabled' => false]);
    Compose::register($on)->register($off);

    expect($fake)->toBeInstanceOf(ComposeFake::class)
        ->and(Compose::redeploy())->toBe(['on' => RedeployResult::Redeployed, 'off' => RedeployResult::Skipped])
        ->and($fake->results())->toBe(['on' => RedeployResult::Redeployed, 'off' => RedeployResult::Skipped])
        ->and(File::exists($on->directory().'/.env'))->toBeFalse();

    Process::assertNothingRan();

    $fake->assertRedeployed('on')->assertSkipped('off')->assertNotRedeployed('off');
});

it('fails its assertions with a readable reason', function (): void {
    $fake = Compose::fake();
    Compose::register(fakeStack(['name' => 'on']))->register(fakeStack(['name' => 'off', 'enabled' => false]));

    $fake->assertNothingRedeployed();
    Compose::redeploy();

    expect(fn () => $fake->assertRedeployed('off'))->toThrow(AssertionFailedError::class, 'Expected stack [off] to be redeployed, but it was skipped.')
        ->and(fn () => $fake->assertRedeployed('ghost'))->toThrow(AssertionFailedError::class, 'never redeployed')
        ->and(fn () => $fake->assertSkipped('on'))->toThrow(AssertionFailedError::class, 'Expected stack [on] to be skipped, but it was redeployed.')
        ->and(fn () => $fake->assertNotRedeployed('on'))->toThrow(AssertionFailedError::class, 'not to be redeployed')
        ->and(fn () => $fake->assertNothingRedeployed())->toThrow(AssertionFailedError::class, 'these were: on');
});

it('can force a failure and respects the master switch', function (): void {
    $fake = Compose::fake();
    Compose::register(fakeStack(['name' => 'flaky']))->register(fakeStack(['name' => 'fine']));
    $fake->shouldFail('flaky');

    expect(Compose::redeploy('flaky'))->toBe(['flaky' => RedeployResult::Failed])
        ->and(Compose::redeploy('flaky'))->toBe(['flaky' => RedeployResult::Redeployed]);

    config()->set('compose.enabled', false);

    expect(Compose::redeploy('fine'))->toBe(['fine' => RedeployResult::Skipped]);
});

it('keeps a process fake that was installed first', function (): void {
    Process::fake(['*ps*' => Process::result('{"Name":"quiet-app","Service":"app","State":"running","Health":"","Status":"Up","ExitCode":0,"Publishers":[]}')]);
    Compose::fake();
    Compose::register(fakeStack(['name' => 'quiet']));

    expect(Compose::stack('quiet')->isRunning())->toBeTrue();
});

it('keeps stack helpers away from a real daemon', function (): void {
    Compose::fake();
    $stack = fakeStack(['name' => 'quiet']);
    Compose::register($stack);

    expect(Compose::stack('quiet')->isRunning())->toBeFalse();

    Process::assertRan(fn ($process): bool => in_array('ps', $process->command, true));
});
