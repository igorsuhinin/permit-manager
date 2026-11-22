<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Command\RecordPermitCheckCommand;
use App\Application\Command\RecordPermitCheckHandler;
use App\Application\Enum\PermitCheckOutcome;
use App\Application\Port\PermitCheckRecorderInterface;
use App\Application\View\PermitCheckRecord;
use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Service\PermitExpiration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[Small]
#[CoversClass(RecordPermitCheckHandler::class)]
#[UsesClass(PermitCheckRecord::class)]
#[UsesClass(RecordPermitCheckCommand::class)]
#[UsesClass(PermitExpiration::class)]
final class RecordPermitCheckHandlerTest extends TestCase
{
    #[DataProvider('requestedIds')]
    public function testRecordedMetadataHasBoundedFields(?string $id, ?string $expected, bool $truncated): void
    {
        $time = new \DateTimeImmutable('2026-11-03T12:00:00+02:00');
        $store = $this->createMock(PermitCheckRecorderInterface::class);
        $store->expects($this->once())->method('record')->with(self::callback(
            static fn(PermitCheckRecord $record): bool => $expected === $record->permitId
                && $truncated === $record->idTruncated
            && str_repeat('G', 16) === $record->method && str_repeat('/', 32) === $record->path
            && PermitCheckOutcome::Rejected === $record->outcome && PermitFailureReason::Expired === $record->reason
            && 200 === $record->status && $time === $record->checkedAt,
        ));
        new RecordPermitCheckHandler(
            $store,
            new NullLogger(),
        )->handle(new RecordPermitCheckCommand(
            id: $id,
            method: str_repeat('G', 20),
            path: str_repeat('/', 40),
            outcome: PermitCheckOutcome::Rejected,
            reason: PermitFailureReason::Expired,
            status: 200,
            checkedAt: $time,
        ));
    }

    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function requestedIds(): iterable
    {
        yield 'missing' => [null, null, false];
        yield 'exact byte limit' => [str_repeat('a', 255), str_repeat('a', 255), false];
        yield 'truncated ASCII' => [str_repeat('a', 256), str_repeat('a', 255), true];
        yield 'whole UTF-8 characters' => [str_repeat('ā', 140), str_repeat('ā', 127), true];
    }

    #[DataProvider('submittedDetails')]
    public function testSubmittedDetailsAreOptionalAndBounded(
        ?string $term,
        ?string $apartment,
        ?string $expiresOn,
        ?string $expectedApartment,
    ): void {
        $store = $this->createMock(PermitCheckRecorderInterface::class);
        $store->expects($this->once())->method('record')->with(self::callback(
            static fn(PermitCheckRecord $record): bool => $record->expiresOn?->format('Y-m-d') === $expiresOn
                && $record->apartment === $expectedApartment,
        ));
        new RecordPermitCheckHandler($store, new NullLogger())->handle(new RecordPermitCheckCommand(
            id: 'legacy',
            method: 'GET',
            path: '/p/check',
            outcome: PermitCheckOutcome::Valid,
            reason: null,
            status: 200,
            checkedAt: new \DateTimeImmutable(),
            term: $term,
            apartment: $apartment,
        ));
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string}> */
    public static function submittedDetails(): iterable
    {
        yield 'legacy details' => ['31.12.2099', '12', '2099-12-31', '12'];
        yield 'missing' => [null, null, null, null];
        yield 'invalid date and blank apartment' => ['31.02.2099', ' ', null, null];
        yield 'oversized apartment' => ['31.12.2099', str_repeat('a', 256), '2099-12-31', null];
        yield 'exact byte limit' => [null, str_repeat('a', 255), null, str_repeat('a', 255)];
        yield 'unsupported SQL date' => ['31.12.0999', null, null, null];
        yield 'earliest SQL year' => ['01.01.1000', null, '1000-01-01', null];
        yield 'invalid UTF-8' => [null, "12\xff", null, '12?'];
    }

    public function testStorageFailureIsLoggedWithoutRequestValues(): void
    {
        $store = self::createStub(PermitCheckRecorderInterface::class);
        $store->method('record')->willThrowException(new \RuntimeException('storage unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Unable to store permit check history',
            ['error_type' => \RuntimeException::class],
        );
        new RecordPermitCheckHandler(
            $store,
            $logger,
        )->handle(new RecordPermitCheckCommand(
            id: 'private-id',
            method: 'GET',
            path: '/p/check',
            outcome: PermitCheckOutcome::Error,
            reason: PermitFailureReason::InternalError,
            status: 500,
            checkedAt: new \DateTimeImmutable(),
        ));
    }
}
