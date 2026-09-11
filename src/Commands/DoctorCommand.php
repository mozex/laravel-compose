<?php

declare(strict_types=1);

namespace Mozex\Compose\Commands;

use Illuminate\Console\Command;
use Mozex\Compose\Doctor\Severity;
use Mozex\Compose\Doctor\Validator;

class DoctorCommand extends Command
{
    protected $signature = 'compose:doctor {--no-daemon : Skip the checks that talk to the docker binary and daemon}';

    protected $description = 'Check docker, every stack, and the host setup before a deploy relies on them';

    public function handle(Validator $validator): int
    {
        $report = $validator->run(! $this->option('no-daemon'));

        foreach ($report->problems as $problem) {
            match ($problem->severity) {
                Severity::Error => $this->components->error($problem->describe()),
                Severity::Warning => $this->components->warn($problem->describe()),
                Severity::Info => $this->components->info($problem->describe()),
            };
        }

        $errors = count($report->errors());
        $warnings = count($report->warnings());

        if ($report->isClean()) {
            $this->components->info($warnings === 0 ? 'Everything checks out.' : "No errors, {$warnings} warning(s).");

            return self::SUCCESS;
        }

        $this->components->error("{$errors} error(s), {$warnings} warning(s).");

        return self::FAILURE;
    }
}
