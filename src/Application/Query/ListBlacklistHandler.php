<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Port\BlacklistQueriesInterface;
use App\Application\Support\Pagination;
use App\Application\View\BlacklistItem;
use App\Application\View\Page;

final readonly class ListBlacklistHandler
{
    public function __construct(private BlacklistQueriesInterface $blacklist) {}

    /** @return Page<BlacklistItem> */
    public function handle(ListBlacklistQuery $query): Page
    {
        return Pagination::page($this->blacklist->search(
            search: $query->search,
            offset: Pagination::offset($query->page),
            limit: Pagination::FETCH_LIMIT,
        ));
    }
}
