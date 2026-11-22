<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Configuration\Settings;
use App\Application\Port\BlacklistQueriesInterface;
use App\Application\Port\PermitQueriesInterface;
use App\Application\Support\Pagination;
use App\Application\View\Page;
use App\Application\View\PermitListItem;
use App\Application\View\PermitRecord;
use App\Domain\Permit\Enum\PermitStatus;
use App\Domain\Permit\Service\PermitExpiration;
use Psr\Clock\ClockInterface;

final readonly class ListPermitsHandler
{
    public function __construct(
        private PermitQueriesInterface $permits,
        private BlacklistQueriesInterface $blacklist,
        private Settings $settings,
        private ClockInterface $clock,
    ) {}

    /** @return Page<PermitListItem> */
    public function handle(ListPermitsQuery $query): Page
    {
        $records = $this->permits->search(
            search: $query->search,
            offset: Pagination::offset($query->page),
            limit: Pagination::FETCH_LIMIT,
        );
        $blocked = array_fill_keys($this->blacklist->matchingIds(array_map(
            static fn(PermitRecord $record): string => $record->permit->id,
            $records,
        )), true);
        $timezone = new \DateTimeZone($this->settings->timezone);
        $today = $this->clock->now()->setTimezone($timezone)->format('Y-m-d');
        $items = [];
        foreach ($records as $record) {
            $expiry = PermitExpiration::parse($record->permit->term, $timezone);
            $status = isset($blocked[$record->permit->id]) ? PermitStatus::Revoked
                : (null === $expiry || $expiry->format('Y-m-d') < $today ? PermitStatus::Expired : PermitStatus::Valid);
            $items[] = new PermitListItem($record, $status);
        }

        return Pagination::page($items);
    }
}
