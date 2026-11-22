<?php

declare(strict_types=1);

namespace App\Application\Enum;

enum AdminAuditAction: string
{
    case PermitIssued = 'permit_issued';
    case BlacklistAdded = 'blacklist_added';
    case BlacklistRemoved = 'blacklist_removed';
    case Login = 'login';
    case Logout = 'logout';
}
