<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\View\AdminAuditEntry;

interface AdminAuditTrailInterface
{
    public function append(AdminAuditEntry $entry): void;
}
