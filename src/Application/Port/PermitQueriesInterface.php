<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\View\PermitRecord;

interface PermitQueriesInterface
{
    /** @return list<PermitRecord> */
    public function search(string $search, int $offset, int $limit): array;
}
