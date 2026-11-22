<?php

declare(strict_types=1);

namespace App\Domain\Permit\Enum;

enum PermitSignatureVersion: int
{
    case Legacy = 1;
    case HmacSha256 = 2;
}
