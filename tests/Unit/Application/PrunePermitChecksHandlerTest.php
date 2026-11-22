<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Command\PrunePermitChecksCommand;
use App\Application\Command\PrunePermitChecksHandler;
use App\Application\Port\MaintenanceLockInterface;
use App\Application\Port\PermitCheckPrunerInterface;
use App\Application\Validator\PrunePermitChecksValidator;
use App\Application\View\HistoryPruneResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FrozenClock;

#[Small]
#[CoversClass(PrunePermitChecksHandler::class)]
#[CoversClass(PrunePermitChecksValidator::class)]
#[UsesClass(PrunePermitChecksCommand::class)]
#[UsesClass(HistoryPruneResult::class)]
final class PrunePermitChecksHandlerTest extends TestCase
{
    private PermitCheckPrunerInterface&MockObject $checks;
    private MaintenanceLockInterface&MockObject $lock;

    #[\Override]
    protected function setUp(): void
    {
        $this->checks = $this->createMock(PermitCheckPrunerInterface::class);
        $this->lock = $this->createMock(MaintenanceLockInterface::class);
    }

    private function handler(): PrunePermitChecksHandler
    {
        return new PrunePermitChecksHandler(
            checks: $this->checks,
            validator: new PrunePermitChecksValidator(),
            lock: $this->lock,
            clock: new FrozenClock(new \DateTimeImmutable('2026-11-11T02:00:00+02:00')),
            logger: new NullLogger(),
        );
    }

    #[DataProvider('previews')]
    public function testPreviewIsBoundedAndNeverDeletes(int $count, int $rows, bool $hasMore): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');
        $this->checks->expects($this->once())->method('countBefore')
            ->with('2026-11-01 00:00:00', 5)->willReturn($count);
        $this->checks->expects($this->never())->method('deleteBefore');
        $result = $this->handler()->handle(new PrunePermitChecksCommand(
            retentionDays: 10,
            batchSize: 2,
            maxBatches: 2,
        ));
        self::assertSame('2026-11-01 00:00:00', $result->cutoff);
        self::assertSame($rows, $result->rows);
        self::assertSame($hasMore, $result->hasMore);
        self::assertFalse($result->skipped);
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function previews(): iterable
    {
        yield 'empty' => [0, 0, false];
        yield 'exact budget' => [4, 4, false];
        yield 'backlog' => [5, 4, true];
    }

    /** @param list<int> $deleted */
    #[DataProvider('deletions')]
    public function testApplyHonorsBatchAndRunLimits(array $deleted, int $remaining, int $rows): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');
        $this->checks->expects($this->exactly(count($deleted)))->method('deleteBefore')
            ->with('2026-11-01 00:00:00', 2)->willReturnOnConsecutiveCalls(...$deleted);
        $this->checks->expects($this->once())->method('countBefore')->with('2026-11-01 00:00:00', 1)
            ->willReturn($remaining);
        $result = $this->handler()->handle(new PrunePermitChecksCommand(
            retentionDays: 10,
            batchSize: 2,
            maxBatches: 2,
            dryRun: false,
        ));
        self::assertSame($rows, $result->rows);
        self::assertSame($remaining > 0, $result->hasMore);
    }

    /** @return iterable<string, array{list<int>, int, int}> */
    public static function deletions(): iterable
    {
        yield 'empty' => [[0], 0, 0];
        yield 'partial batch' => [[1], 0, 1];
        yield 'exact batch then empty' => [[2, 0], 0, 2];
        yield 'budget reached' => [[2, 2], 1, 4];
    }

    public function testOverlappingRunDoesNotReadOrDeleteOrReleaseTheOtherLock(): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(false);
        $this->lock->expects($this->never())->method('release');
        $this->checks->expects($this->never())->method('countBefore');
        $this->checks->expects($this->never())->method('deleteBefore');
        $result = $this->handler()->handle(new PrunePermitChecksCommand(
            retentionDays: 10,
            batchSize: 2,
            maxBatches: 2,
        ));
        self::assertTrue($result->skipped);
        self::assertSame(0, $result->rows);
    }

    public function testFailureReleasesTheLockAndPropagates(): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');
        $this->checks->expects($this->once())->method('deleteBefore')->willThrowException(new \RuntimeException());
        $this->expectException(\RuntimeException::class);
        $this->handler()->handle(new PrunePermitChecksCommand(
            retentionDays: 10,
            batchSize: 2,
            maxBatches: 2,
            dryRun: false,
        ));
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsCannotAcquireALockOrDelete(int $days, int $size, int $batches): void
    {
        $this->lock->expects($this->never())->method('acquire');
        $this->checks->expects($this->never())->method('deleteBefore');
        $this->expectException(\InvalidArgumentException::class);
        $this->handler()->handle(new PrunePermitChecksCommand(
            retentionDays: $days,
            batchSize: $size,
            maxBatches: $batches,
            dryRun: false,
        ));
    }

    /** @return iterable<array{int, int, int}> */
    public static function invalidOptions(): iterable
    {
        yield [0, 1, 1];
        yield [36501, 1, 1];
        yield [1, 0, 1];
        yield [1, 1001, 1];
        yield [1, 1, 0];
        yield [1, 1, 101];
    }

    public function testMaximumOptionsAreAccepted(): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');
        $this->checks->expects($this->once())->method('countBefore')->with(self::isString(), 100001)->willReturn(0);
        $this->checks->expects($this->never())->method('deleteBefore');
        $result = $this->handler()->handle(new PrunePermitChecksCommand(
            retentionDays: 36500,
            batchSize: 1000,
            maxBatches: 100,
        ));
        self::assertSame(0, $result->rows);
    }
}
