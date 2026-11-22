<?php

declare(strict_types=1);

namespace App\Application\Enum;

enum PermitCheckOutcome: string
{
    case Valid = 'valid';
    case Rejected = 'rejected';
    case Malformed = 'malformed';
    case Error = 'error';
}
