<?php

declare(strict_types=1);

namespace App\Http\Response;

enum AdminNotice: string
{
    case BlacklistAdded = 'notice.blacklist_added';
    case BlacklistRemoved = 'notice.blacklist_removed';
    case PermitGenerated = 'notice.permit_generated';
    case PermitReprinted = 'notice.permit_reprinted';
}
