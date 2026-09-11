<?php

declare(strict_types=1);

use Mozex\Compose\Doctor\Problem;
use Mozex\Compose\Doctor\Report;
use Mozex\Compose\Doctor\Severity;

it('groups problems by severity and stack', function (): void {
    $report = new Report([
        Problem::error('daemon down'),
        Problem::warning('public port', 'meili'),
        Problem::info('disabled', 'mail'),
        Problem::error('missing var', 'meili'),
    ]);

    expect($report->errors())->toHaveCount(2)
        ->and($report->warnings())->toHaveCount(1)
        ->and($report->notes())->toHaveCount(1)
        ->and($report->forStack('meili'))->toHaveCount(2)
        ->and($report->forStack('ghost'))->toBe([])
        ->and($report->isClean())->toBeFalse()
        ->and($report->hasWarnings())->toBeTrue()
        ->and($report->lines())->toBe(['error: daemon down', 'error: [meili] missing var', 'warning: [meili] public port'])
        ->and($report->problems[0]->severity)->toBe(Severity::Error);
});

it('is clean with only warnings and notes', function (): void {
    $report = new Report([Problem::warning('careful'), Problem::info('fyi')]);

    expect($report->isClean())->toBeTrue()
        ->and($report->hasWarnings())->toBeTrue()
        ->and((new Report([]))->isClean())->toBeTrue()
        ->and((new Report([]))->hasWarnings())->toBeFalse()
        ->and(Problem::info('plain')->describe())->toBe('plain')
        ->and(Problem::info('scoped', 'meili')->describe())->toBe('[meili] scoped');
});
