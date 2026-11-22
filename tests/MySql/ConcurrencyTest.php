<?php

declare(strict_types=1);

namespace Tests\MySql;

use App\Application\Authentication\LoginRateLimiter;
use App\Application\Command\GeneratePermitCommand;
use App\Application\Command\GeneratePermitHandler;
use App\Domain\Permit\Model\Permit;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\LoginAttemptStore;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;
use Tests\Support\ConcurrentProcesses;
use Tests\Support\FrozenClock;
use Tests\Support\MySqlDatabase;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class ConcurrencyTest extends TestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        MySqlDatabase::migrate('0');
        MySqlDatabase::migrate();
        $this->connection = MySqlDatabase::connect();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
        $cleanup = MySqlDatabase::connect();
        $cleanup->executeStatement('DELETE FROM permits');
        $cleanup->close();
    }

    public function testSimultaneousDuplicateIssuanceCommitsOneRecordAndOneAuditEntry(): void
    {
        $results = ConcurrentProcesses::run(['issue', 'issue']);
        self::assertSame(['ok', 'ok'], array_column($results, 'result'));
        self::assertSame($results[0]['url'], $results[1]['url']);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit'));
        self::assertSame(
            $this->connection->fetchOne('SELECT fingerprint FROM permits'),
            $this->connection->fetchOne('SELECT target_hash FROM admin_audit'),
        );
    }

    public function testSimultaneousDuplicateRevocationAuditsOnlyTheWinner(): void
    {
        $results = array_column(ConcurrentProcesses::run(['add', 'add']), 'result');
        sort($results);
        self::assertSame(['duplicate', 'ok'], $results);
        self::assertTrue(new BlacklistRepository($this->connection)->contains('DEMO-RACE'));
        self::assertSame(['blacklist_added'], $this->connection->fetchFirstColumn('SELECT action FROM admin_audit'));
    }

    public function testSimultaneousRemovalsDoNotAuditANoOp(): void
    {
        new BlacklistRepository($this->connection)->add('DEMO-RACE');
        self::assertSame(['ok', 'ok'], array_column(ConcurrentProcesses::run(['remove', 'remove']), 'result'));
        self::assertFalse(new BlacklistRepository($this->connection)->contains('DEMO-RACE'));
        self::assertSame(['blacklist_removed'], $this->connection->fetchFirstColumn('SELECT action FROM admin_audit'));
    }

    public function testConcurrentAddAndRemoveAgreeWithTheLastCommittedAuditEntry(): void
    {
        self::assertSame(['ok', 'ok'], array_column(ConcurrentProcesses::run(['add', 'remove']), 'result'));
        $actions = $this->connection->fetchFirstColumn('SELECT action FROM admin_audit ORDER BY id');
        self::assertContains($actions, [['blacklist_added'], ['blacklist_added', 'blacklist_removed']]);
        self::assertSame(
            'blacklist_added' === array_last($actions),
            new BlacklistRepository($this->connection)->contains('DEMO-RACE'),
        );
    }

    public function testConcurrentFailedLoginsDoNotLoseCountsAndSubsequentAttemptsAreThrottled(): void
    {
        $results = array_column(
            ConcurrentProcesses::run(array_fill(start_index: 0, count: 8, value: 'login')),
            'result',
        );
        $failures = count(array_filter($results, static fn(string $result): bool => 'failed' === $result));
        self::assertGreaterThanOrEqual(5, $failures);
        self::assertLessThanOrEqual(8, $failures);
        $attempt = new LoginAttemptStore($this->connection)->find(hash(algo: 'sha256', data: '192.0.2.1'));
        self::assertSame(['attempts' => $failures, 'startedAt' => 1794398400], $attempt);
        self::assertSame(900, new LoginRateLimiter(
            new LoginAttemptStore($this->connection),
            new FrozenClock(new \DateTimeImmutable('2026-11-11T12:00:00Z')),
        )->retryAfter('192.0.2.1'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit'));
    }

    public function testAuditFailureRollsBackMysqlIssuanceAndRetrySucceeds(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/../../config/container.php');
        $builder->addDefinitions([Connection::class => $this->connection, LoggerInterface::class => new NullLogger()]);
        /** @var GeneratePermitHandler $handler */
        $handler = $builder->build()->get(GeneratePermitHandler::class);
        $command = new GeneratePermitCommand(
            permit: new Permit(id: 'DEMO-ATOMIC', term: '31.12.2099', plate: 'TEST'),
            actor: 'admin',
        );
        $this->connection->executeStatement(
            "ALTER TABLE admin_audit ADD CONSTRAINT unavailable_audit CHECK (action = 'unavailable')",
        );
        try {
            try {
                $handler->handle($command);
                self::fail('The required audit write must fail.');
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::assertStringContainsString('unavailable_audit', $exception->getMessage());
            }
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit'));
            self::assertFalse($this->connection->isTransactionActive());
        } finally {
            $this->connection->executeStatement('ALTER TABLE admin_audit DROP CHECK unavailable_audit');
        }
        $handler->handle($command);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit'));
    }

    #[DataProvider('outagePaths')]
    public function testLostDatabaseConnectionProducesPrivateHttpErrors(string $path): void
    {
        $logs = new TestHandler();
        $app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$logs]),
            connection: $this->connection,
        );
        /** @var int|string $id */
        $id = $this->connection->fetchOne('SELECT CONNECTION_ID()');
        $other = MySqlDatabase::connect();
        $other->executeStatement('KILL CONNECTION ' . (int) $id);
        $other->close();
        $response = $app->handle(new ServerRequestFactory()->createServerRequest(method: 'GET', uri: $path)
            ->withQueryParams([
                'id' => 'DEMO-OUTAGE', 'plate' => 'TEST', 'term' => '31.12.2099',
                'sign' => '7f9517f1675eca7f5eeca7666aa88852',
            ]));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        foreach (['SQLSTATE', 'test-secret', 'DEMO-OUTAGE', 'permits_test', 'Stack trace', 'Ir derīga'] as $detail) {
            self::assertStringNotContainsString($detail, (string) $response->getBody());
        }
        self::assertTrue($logs->hasRecord('HTTP request failed', Level::Error));
    }

    /** @return iterable<array{string}> */
    public static function outagePaths(): iterable
    {
        yield ['/p/check'];
        yield ['/p/check.php'];
        yield ['/admin/login'];
    }
}
