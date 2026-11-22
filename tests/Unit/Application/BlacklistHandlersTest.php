<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Audit\AdminAudit;
use App\Application\Command\AddBlacklistedIdCommand;
use App\Application\Command\AddBlacklistedIdHandler;
use App\Application\Command\RemoveBlacklistedIdCommand;
use App\Application\Command\RemoveBlacklistedIdHandler;
use App\Application\Exception\DuplicateBlacklistEntryException;
use App\Application\Port\AdminAuditTrailInterface;
use App\Application\Port\BlacklistManagementInterface;
use App\Application\View\AdminAuditEntry;
use App\Domain\Permit\Exception\InvalidBlacklistEntryException;
use App\Domain\Permit\Model\BlacklistEntry;
use App\Domain\Permit\Validator\BlacklistEntryValidator;
use App\Domain\Permit\Validator\PermitIdValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Support\FrozenClock;
use Tests\Support\ImmediateTransaction;

#[Small]
#[UsesClass(AdminAudit::class)]
#[UsesClass(AdminAuditEntry::class)]
#[UsesClass(PermitIdValidator::class)]
#[CoversClass(AddBlacklistedIdHandler::class)]
#[CoversClass(RemoveBlacklistedIdHandler::class)]
#[UsesClass(AddBlacklistedIdCommand::class)]
#[UsesClass(RemoveBlacklistedIdCommand::class)]
#[UsesClass(BlacklistEntry::class)]
#[UsesClass(BlacklistEntryValidator::class)]
final class BlacklistHandlersTest extends TestCase
{
    private function audit(): AdminAudit
    {
        return new AdminAudit(
            self::createStub(AdminAuditTrailInterface::class),
            new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
        );
    }

    public function testAddAndRemoveLogHashesAndPreserveExactId(): void
    {
        $id = ' DEMO-1 ';
        $store = $this->createMock(BlacklistManagementInterface::class);
        $store->expects($this->once())->method('add')->with($id, null)->willReturn(true);
        $store->expects($this->once())->method('remove')->with($id)->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $contexts = [];
        $logger->expects($this->exactly(2))->method('info')->willReturnCallback(static function (
            string $message,
            array $context,
        ) use (&$contexts): void {
            self::assertSame('Admin blacklist changed', $message);
            $contexts[] = $context;
        });
        new AddBlacklistedIdHandler(
            validator: new BlacklistEntryValidator(new PermitIdValidator()),
            blacklist: $store,
            audit: $this->audit(),
            transactions: new ImmediateTransaction(),
            logger: $logger,
        )->handle(
            new AddBlacklistedIdCommand(id: $id, reason: '  ', actor: 'admin'),
        );
        new RemoveBlacklistedIdHandler(
            validator: new BlacklistEntryValidator(new PermitIdValidator()),
            blacklist: $store,
            audit: $this->audit(),
            transactions: new ImmediateTransaction(),
            logger: $logger,
        )->handle(
            new RemoveBlacklistedIdCommand(id: $id, reason: '', actor: 'admin'),
        );
        self::assertSame([
            ['action' => 'add', 'id_hash' => hash(algo: 'sha256', data: $id)],
            ['action' => 'remove', 'id_hash' => hash(algo: 'sha256', data: $id)],
        ], $contexts);
    }

    public function testDuplicateIsReportedWithoutChangingItsReason(): void
    {
        $store = $this->createMock(BlacklistManagementInterface::class);
        $store->expects($this->once())->method('add')->with('DEMO-1', 'New reason')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $this->expectException(DuplicateBlacklistEntryException::class);
        new AddBlacklistedIdHandler(
            validator: new BlacklistEntryValidator(new PermitIdValidator()),
            blacklist: $store,
            audit: $this->audit(),
            transactions: new ImmediateTransaction(),
            logger: $logger,
        )->handle(new AddBlacklistedIdCommand(id: 'DEMO-1', reason: 'New reason', actor: 'admin'));
    }

    #[DataProvider('invalidChanges')]
    public function testInvalidInputNeverWritesOrLogs(bool $remove, string $id, string $reason): void
    {
        $store = $this->createMock(BlacklistManagementInterface::class);
        $store->expects($this->never())->method('add');
        $store->expects($this->never())->method('remove');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $validator = new BlacklistEntryValidator(new PermitIdValidator());
        $this->expectException(InvalidBlacklistEntryException::class);
        if ($remove) {
            new RemoveBlacklistedIdHandler(
                validator: $validator,
                blacklist: $store,
                audit: $this->audit(),
                transactions: new ImmediateTransaction(),
                logger: $logger,
            )->handle(
                new RemoveBlacklistedIdCommand(id: $id, reason: $reason, actor: 'admin'),
            );
        } else {
            new AddBlacklistedIdHandler(
                validator: $validator,
                blacklist: $store,
                audit: $this->audit(),
                transactions: new ImmediateTransaction(),
                logger: $logger,
            )->handle(
                new AddBlacklistedIdCommand(id: $id, reason: $reason, actor: 'admin'),
            );
        }
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function invalidChanges(): iterable
    {
        yield 'add empty ID' => [false, '', ''];
        yield 'remove empty ID' => [true, '', ''];
        yield 'add oversized blank reason' => [false, 'DEMO-1', str_repeat(' ', 2001)];
        yield 'remove oversized blank reason' => [true, 'DEMO-1', str_repeat(' ', 2001)];
    }
}
