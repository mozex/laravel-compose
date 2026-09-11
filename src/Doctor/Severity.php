<?php

declare(strict_types=1);

namespace Mozex\Compose\Doctor;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
