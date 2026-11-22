<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Port\MaintenanceLockInterface;
use App\Console\Command\PruneChecksCommand;
use App\Infrastructure\Maintenance\FileMaintenanceLock;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\Database;
use Tests\Support\FrozenClock;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class HistoryMaintenanceTest extends TestCase
{
    private Connection $connection;
    private string $directory;
    private FileMaintenanceLock $lock;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = Database::create();
        $this->directory = sys_get_temp_dir() . '/permit-maintenance-' . bin2hex(random_bytes(8));
        $this->lock = new FileMaintenanceLock($this->directory . '/history.lock');
        foreach (['2026-10-03', '2026-11-01', '2026-10-01', '2026-10-02', '2026-11-02'] as $date) {
            $this->connection->insert('permit_checks', [
                'permit_id' => 'DEMO-' . $date, 'id_truncated' => 0, 'method' => 'GET', 'path' => '/p/check',
                'result' => 'valid', 'reason' => null, 'status' => 200, 'checked_at' => $date . ' 00:00:00',
            ]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->lock->release();
        if (is_dir($this->directory)) {
            unlink($this->directory . '/history.lock');
            rmdir($this->directory);
        }
        $this->connection->close();
    }

    private function tester(?LoggerInterface $logger = null): CommandTester
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/../../config/container.php');
        $builder->addDefinitions([
            Connection::class => $this->connection,
            ClockInterface::class => new FrozenClock(new \DateTimeImmutable('2026-11-11T00:00:00Z')),
            MaintenanceLockInterface::class => $this->lock,
            LoggerInterface::class => $logger ?? new NullLogger(),
        ]);
        $container = $builder->build();
        /** @var PruneChecksCommand $command */
        $command = $container->get(PruneChecksCommand::class);

        return new CommandTester($command);
    }

    public function testCliPreviewApplyResumeAndCutoffWithoutChangingOtherData(): void
    {
        $this->connection->insert('admin_audit', [
            'actor' => 'admin', 'action' => 'login', 'target_hash' => hash(algo: 'sha256', data: 'admin'),
            'occurred_at' => '2000-01-01 00:00:00',
        ]);
        $audit = $this->connection->fetchAllAssociative('SELECT * FROM admin_audit');
        $settings = $this->connection->fetchAllAssociative('SELECT * FROM app_settings');
        $blacklist = $this->connection->fetchAllAssociative('SELECT * FROM blacklisted_ids');
        $tester = $this->tester();
        $options = ['--days' => 10, '--batch-size' => 2, '--max-batches' => 1];
        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString('Mode: dry-run', $tester->getDisplay());
        self::assertStringContainsString('Would delete: 2', $tester->getDisplay());
        self::assertStringContainsString('More eligible rows: yes', $tester->getDisplay());
        self::assertSame(5, $this->connection->fetchOne('SELECT COUNT(*) FROM permit_checks'));
        self::assertSame(Command::SUCCESS, $tester->execute($options + ['--apply' => true]));
        self::assertStringContainsString('Deleted: 2', $tester->getDisplay());
        self::assertSame(
            ['DEMO-2026-10-03', 'DEMO-2026-11-01', 'DEMO-2026-11-02'],
            $this->connection->fetchFirstColumn('SELECT permit_id FROM permit_checks ORDER BY id'),
        );
        self::assertSame(Command::SUCCESS, $tester->execute($options + ['--apply' => true]));
        self::assertStringContainsString('Deleted: 1', $tester->getDisplay());
        self::assertStringContainsString('More eligible rows: no', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute($options + ['--apply' => true]));
        self::assertStringContainsString('Deleted: 0', $tester->getDisplay());
        self::assertSame(
            ['DEMO-2026-11-01', 'DEMO-2026-11-02'],
            $this->connection->fetchFirstColumn('SELECT permit_id FROM permit_checks ORDER BY id'),
        );
        self::assertSame($audit, $this->connection->fetchAllAssociative('SELECT * FROM admin_audit'));
        self::assertSame($settings, $this->connection->fetchAllAssociative('SELECT * FROM app_settings'));
        self::assertSame($blacklist, $this->connection->fetchAllAssociative('SELECT * FROM blacklisted_ids'));
    }

    public function testFileConfigurationAndExplicitDryRunAreUsed(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Cutoff (UTC, exclusive): 2026-08-13 00:00:00', $tester->getDisplay());
        self::assertStringContainsString('Would delete: 0', $tester->getDisplay());
    }

    public function testOverlappingInstanceCannotDeleteOrReleaseTheOriginalLock(): void
    {
        $other = new FileMaintenanceLock($this->directory . '/history.lock');
        self::assertTrue($other->acquire());
        try {
            $tester = $this->tester();
            self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--days' => 10]));
            self::assertStringContainsString('Skipped: another history maintenance process', $tester->getDisplay());
            self::assertFalse($this->lock->acquire());
            self::assertFalse($other->acquire());
            self::assertSame(5, $this->connection->fetchOne('SELECT COUNT(*) FROM permit_checks'));
        } finally {
            $other->release();
        }
        self::assertTrue($this->lock->acquire());
    }

    public function testStorageFailureIsGenericAndReleasesTheLock(): void
    {
        $tester = $this->tester();
        $this->connection->executeStatement('DROP TABLE permit_checks');
        self::assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('History maintenance failed.', $tester->getDisplay());
        self::assertStringNotContainsString('permit_checks', $tester->getDisplay());
        self::assertTrue(new FileMaintenanceLock($this->directory . '/history.lock')->acquire());
    }

    public function testLoggerFailureCannotExposeAnInternalConsoleError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->willThrowException(new \RuntimeException('private failure'));
        $tester = $this->tester($logger);
        $this->connection->executeStatement('DROP TABLE permit_checks');
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('History maintenance failed.', $tester->getDisplay());
        self::assertStringNotContainsString('private failure', $tester->getDisplay());
    }

    /** @param array<string, int|bool> $options */
    #[DataProvider('invalidOptions')]
    public function testCliRejectsUnsafeOptions(array $options): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute($options));
        self::assertSame(5, $this->connection->fetchOne('SELECT COUNT(*) FROM permit_checks'));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    /** @return iterable<string, array{array<string, int|bool>}> */
    public static function invalidOptions(): iterable
    {
        yield 'contradictory modes' => [['--apply' => true, '--dry-run' => true]];
        yield 'zero retention' => [['--days' => 0]];
        yield 'unbounded batch' => [['--batch-size' => 1001, '--apply' => true]];
        yield 'unbounded run' => [['--max-batches' => 101, '--apply' => true]];
    }
}
