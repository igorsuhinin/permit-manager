<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Port\BlacklistQueriesInterface;
use App\Application\Port\PermitCheckQueriesInterface;
use App\Application\Support\Pagination;
use App\Application\View\Page;
use App\Application\View\PermitCheckListItem;
use App\Domain\Permit\Validator\PermitIdValidator;

final readonly class ListPermitChecksHandler
{
    public function __construct(
        private PermitCheckQueriesInterface $checks,
        private BlacklistQueriesInterface $blacklist,
        private PermitIdValidator $ids,
    ) {}

    /** @return Page<PermitCheckListItem> */
    public function handle(ListPermitChecksQuery $query): Page
    {
        $records = $this->checks->search(
            id: $query->search,
            result: $query->outcome,
            offset: Pagination::offset($query->page),
            limit: Pagination::FETCH_LIMIT,
        );
        $ids = [];
        foreach ($records as $record) {
            if (!$record->idTruncated && null !== $record->permitId && $this->ids->isValid($record->permitId)) {
                $ids[] = $record->permitId;
            }
        }
        $blocked = array_fill_keys($this->blacklist->matchingIds($ids), true);
        $items = [];
        foreach ($records as $record) {
            $blacklisted = null !== $record->permitId && isset($blocked[$record->permitId]);
            $items[] = new PermitCheckListItem(
                record: $record,
                blacklisted: $blacklisted,
                canBlacklist: !$blacklisted && !$record->idTruncated && null !== $record->permitId
                    && $this->ids->isValid($record->permitId),
            );
        }

        return Pagination::page($items);
    }
}
