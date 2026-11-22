<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Permit\Model\Permit;

interface PermitLookupInterface
{
    public function find(string $fingerprint): ?Permit;
}
