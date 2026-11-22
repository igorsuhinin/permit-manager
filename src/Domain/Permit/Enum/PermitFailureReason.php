<?php

declare(strict_types=1);

namespace App\Domain\Permit\Enum;

enum PermitFailureReason: string
{
    case Revoked = 'revoked';
    case Expired = 'expired';
    case InvalidSignature = 'signature';
    case InvalidExpiration = 'expiration';
    case InvalidRequest = 'request';
    case InvalidDetails = 'details';
    case InternalError = 'internal_error';
}
