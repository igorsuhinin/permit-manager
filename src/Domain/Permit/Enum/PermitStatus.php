<?php

declare(strict_types=1);

namespace App\Domain\Permit\Enum;

enum PermitStatus: string
{
    case Valid = 'Valid';
    case Expired = 'Expired';
    case Revoked = 'Revoked';
}
