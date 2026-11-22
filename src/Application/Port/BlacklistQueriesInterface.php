<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\View\BlacklistItem;

interface BlacklistQueriesInterface
{
    /** @return list<BlacklistItem> */
    public function search(string $search, int $offset, int $limit): array;

    /** @param list<string> $ids
     * @return list<string>
     */
    public function matchingIds(array $ids): array;
}
