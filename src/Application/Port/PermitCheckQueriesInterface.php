<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Enum\PermitCheckOutcome;
use App\Application\View\PermitCheckRecord;

interface PermitCheckQueriesInterface
{
    /** @return list<PermitCheckRecord> */
    public function search(string $id, ?PermitCheckOutcome $result, int $offset, int $limit): array;
}
