<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Configuration\Settings;
use App\Application\Enum\PermitCheckOutcome;
use App\Application\Port\BlacklistQueriesInterface;
use App\Application\Port\PermitCheckQueriesInterface;
use App\Application\Port\PermitQueriesInterface;
use App\Application\Query\ListBlacklistHandler;
use App\Application\Query\ListBlacklistQuery;
use App\Application\Query\ListPermitChecksHandler;
use App\Application\Query\ListPermitChecksQuery;
use App\Application\Query\ListPermitsHandler;
use App\Application\Query\ListPermitsQuery;
use App\Application\Support\Pagination;
use App\Application\View\BlacklistItem;
use App\Application\View\PermitCheckRecord;
use App\Application\View\PermitRecord;
use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Enum\PermitStatus;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Validator\PermitIdValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

#[Small]
#[CoversClass(ListPermitsHandler::class)]
#[CoversClass(ListBlacklistHandler::class)]
#[CoversClass(ListPermitChecksHandler::class)]
#[CoversClass(Pagination::class)]
#[UsesClass(ListPermitsQuery::class)]
#[UsesClass(ListBlacklistQuery::class)]
#[UsesClass(ListPermitChecksQuery::class)]
#[UsesNamespace('App\\Application\\View')]
#[UsesClass(Settings::class)]
#[UsesNamespace('App\\Domain')]
final class ListHandlersTest extends TestCase
{
    public function testPermitStatusUsesEndOfDayAndBulkRevocation(): void
    {
        $time = new \DateTimeImmutable('2026-11-03T23:59:59.999999+02:00');
        $expiry = new \DateTimeImmutable('2026-11-03');
        $records = [
            new PermitRecord(
                fingerprint: 'first',
                permit: new Permit(id: 'valid', term: '03.11.2026', plate: 'AB1234'),
                expiresOn: $expiry,
                createdAt: $time,
            ),
            new PermitRecord(
                fingerprint: 'second',
                permit: new Permit(id: 'revoked', term: '01.01.2020', plate: 'AB1234'),
                expiresOn: $expiry,
                createdAt: $time,
            ),
            new PermitRecord(
                fingerprint: 'third',
                permit: new Permit(id: 'expired', term: '02.11.2026', plate: 'AB1234'),
                expiresOn: $expiry,
                createdAt: $time,
            ),
        ];
        $permits = $this->createMock(PermitQueriesInterface::class);
        $permits->expects($this->once())->method('search')->with('search', 25, 26)->willReturn($records);
        $blacklist = $this->createMock(BlacklistQueriesInterface::class);
        $blacklist->expects($this->once())->method('matchingIds')->with([
            'valid',
            'revoked',
            'expired',
        ])->willReturn(['revoked']);
        $service = new ListPermitsHandler(
            permits: $permits,
            blacklist: $blacklist,
            settings: new Settings(hmacKey: str_repeat('h', 32), secret: 'secret', passwordHash: 'hash'),
            clock: new FrozenClock($time),
        );
        $page = $service->handle(new ListPermitsQuery('search', 2));
        self::assertFalse($page->hasNext);
        self::assertSame(
            [PermitStatus::Valid, PermitStatus::Revoked, PermitStatus::Expired],
            array_map(static fn($item) => $item->status, $page->items),
        );
        self::assertSame($records[0], $page->items[0]->record);
    }

    #[DataProvider('pageSizes')]
    public function testBlacklistPaginationFetchesOneExtraRow(int $count, bool $hasNext): void
    {
        $rows = array_fill(start_index: 0, count: $count, value: new BlacklistItem(id: 'id', reason: null));
        $blacklist = $this->createMock(BlacklistQueriesInterface::class);
        $blacklist->expects($this->once())->method('search')->with('literal%', 25, 26)->willReturn($rows);
        $service = new ListBlacklistHandler($blacklist);
        $page = $service->handle(new ListBlacklistQuery('literal%', 2));
        self::assertSame($hasNext, $page->hasNext);
        self::assertCount(min(25, $count), $page->items);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function pageSizes(): iterable
    {
        yield 'empty' => [0, false];
        yield 'full last page' => [25, false];
        yield 'next page exists' => [26, true];
    }

    public function testHistoryQueriesPreserveFiltersAndPagination(): void
    {
        $checks = $this->createMock(PermitCheckQueriesInterface::class);
        $checks->expects($this->once())->method('search')->with(
            'id',
            PermitCheckOutcome::Rejected,
            50,
            26,
        )->willReturn([]);
        $blacklist = $this->createMock(BlacklistQueriesInterface::class);
        $blacklist->expects($this->once())->method('matchingIds')->with([])->willReturn([]);
        $service = new ListPermitChecksHandler($checks, $blacklist, new PermitIdValidator());
        self::assertSame([], $service->handle(new ListPermitChecksQuery('id', PermitCheckOutcome::Rejected, 3))->items);
    }

    public function testHistoryCanBlacklistOnlyCompleteValidIdsAndUsesTheCurrentBlacklist(): void
    {
        $records = [];
        $examples = [['active', false], ['blocked', false], ['long', true], [null, false], ["bad\nID", false]];
        foreach ($examples as [$id, $cut]) {
            $records[] = new PermitCheckRecord(
                permitId: $id,
                idTruncated: $cut,
                method: 'GET',
                path: '/p/check',
                outcome: 'active' === $id ? PermitCheckOutcome::Rejected : PermitCheckOutcome::Valid,
                reason: 'active' === $id ? PermitFailureReason::Revoked : null,
                status: 200,
                checkedAt: new \DateTimeImmutable(),
            );
        }
        $checks = self::createStub(PermitCheckQueriesInterface::class);
        $checks->method('search')->willReturn($records);
        $blacklist = $this->createMock(BlacklistQueriesInterface::class);
        $blacklist->expects($this->once())->method('matchingIds')->with(['active', 'blocked'])->willReturn(['blocked']);
        $items = new ListPermitChecksHandler($checks, $blacklist, new PermitIdValidator())
            ->handle(new ListPermitChecksQuery('', null, 1))->items;
        self::assertSame([true, false, false, false, false], array_column($items, 'canBlacklist'));
        self::assertSame([false, true, false, false, false], array_column($items, 'blacklisted'));
        self::assertSame($records[0], $items[0]->record);
    }
}
