<?php

declare(strict_types=1);

namespace Tests\MySql;

use App\Application\Authentication\AdminSession;
use App\Application\Enum\PermitCheckOutcome;
use App\Application\View\PermitCheckRecord;
use App\Application\View\PermitRecord;
use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Infrastructure\Persistence\AdminSessionRepository;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\DevelopmentDatabaseResetter;
use App\Infrastructure\Persistence\LoginAttemptStore;
use App\Infrastructure\Persistence\PermitCheckPruner;
use App\Infrastructure\Persistence\PermitCheckRepository;
use App\Infrastructure\Persistence\PermitRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Support\MySqlDatabase;

#[Medium]
#[CoversNamespace('App\\Infrastructure\\Persistence')]
#[UsesNamespace('App\\Application')]
#[UsesNamespace('App\\Domain')]
final class PersistenceTest extends TestCase
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
        $this->connection->executeStatement('DELETE FROM permits');
        $this->connection->close();
    }

    #[DataProvider('signatureVersions')]
    public function testDuplicateIssuancePreservesTheOriginalRecordAndTimestamp(PermitSignatureVersion $version): void
    {
        $repository = new PermitRepository($this->connection);
        $permit = new Permit(id: 'A&Ā/🅿', term: '31.12.2099', plate: 'AB1234', signatureVersion: $version);
        $expiration = new \DateTimeImmutable('2099-12-31T23:59:59+02:00');
        $repository->save($permit, $expiration, new \DateTimeImmutable('2026-11-03T12:00:00+02:00'));
        $original = $this->connection->fetchAssociative('SELECT * FROM permits');
        self::assertNotFalse($original);
        $repository->save($permit, $expiration, new \DateTimeImmutable('2026-11-04T12:00:00+02:00'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame($original, $this->connection->fetchAssociative('SELECT * FROM permits'));
        self::assertSame('2026-11-03 10:00:00', $original['created_at']);
        self::assertIsString($original['fingerprint']);
        self::assertSame((array) $permit, (array) $repository->find($original['fingerprint']));
        self::assertSame($version, $repository->search(search: '', offset: 0, limit: 1)[0]->permit->signatureVersion);
    }

    /** @return iterable<string, array{PermitSignatureVersion}> */
    public static function signatureVersions(): iterable
    {
        yield 'legacy' => [PermitSignatureVersion::Legacy];
        yield 'HMAC' => [PermitSignatureVersion::HmacSha256];
    }

    public function testBothVersionsOfIdenticalClaimsHaveSeparateIssuanceRecords(): void
    {
        $repository = new PermitRepository($this->connection);
        foreach ([PermitSignatureVersion::Legacy, PermitSignatureVersion::HmacSha256] as $version) {
            $repository->save(
                new Permit(id: 'DEMO-SAME', term: '31.12.2099', plate: 'TEST', signatureVersion: $version),
                new \DateTimeImmutable('2099-12-31'),
                new \DateTimeImmutable('2026-11-03'),
            );
        }
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $legacy = $repository->find('0478f9b3953771c94bb703c1334f8c40c6e550a5904e9fbdf7af757e79e3be98');
        self::assertNotNull($legacy);
        self::assertSame(PermitSignatureVersion::Legacy, $legacy->signatureVersion);
        $versions = array_map(
            static fn(PermitRecord $row): int => $row->permit->signatureVersion->value,
            $repository->search(search: '', offset: 0, limit: 5),
        );
        sort($versions);
        self::assertSame([1, 2], $versions);
    }

    public function testBlacklistUsesExactIdsDespiteCaseAndAccentInsensitiveCollation(): void
    {
        self::assertSame(1, $this->connection->fetchOne("SELECT _utf8mb4'Ā' = _utf8mb4'a'"));
        $repository = new BlacklistRepository($this->connection);
        self::assertTrue($repository->add(id: 'Permit-Ā', reason: 'original'));
        foreach (['permit-ā', 'Permit-A', 'Permit-Ā ', ' Permit-Ā'] as $id) {
            self::assertFalse($repository->contains($id));
            self::assertSame([], $repository->matchingIds([$id]));
        }
        self::assertTrue($repository->contains('Permit-Ā'));
        self::assertSame(['Permit-Ā'], $repository->matchingIds(['Permit-Ā', 'permit-ā', 'Permit-A']));
        self::assertFalse($repository->add(id: 'Permit-Ā', reason: 'replacement'));
        self::assertSame('original', $repository->search(search: 'Permit-Ā', offset: 0, limit: 1)[0]->reason);
        self::assertTrue($repository->add(id: 'permit-ā', reason: 'separate ID'));
        $repository->remove('Permit-Ā');
        self::assertFalse($repository->contains('Permit-Ā'));
        self::assertTrue($repository->contains('permit-ā'));
    }

    public function testPermitPaginationHasStableOrderingAndLiteralSearch(): void
    {
        $repository = new PermitRepository($this->connection);
        $expiration = new \DateTimeImmutable('2099-12-31');
        $created = new \DateTimeImmutable('2026-11-03T10:00:00+00:00');
        foreach (['A%_!', 'ABC', 'DEF'] as $id) {
            $repository->save(new Permit(id: $id, term: '31.12.2099', plate: 'TEST'), $expiration, $created);
        }
        $repository->save(
            new Permit(id: 'NEWEST', term: '31.12.2099', plate: 'TEST'),
            $expiration,
            $created->modify('+1 second'),
        );
        $expected = $this->connection->fetchFirstColumn(
            'SELECT fingerprint FROM permits ORDER BY created_at DESC, fingerprint ASC',
        );
        $actual = [];
        foreach ([0, 2] as $offset) {
            foreach ($repository->search(search: '', offset: $offset, limit: 2) as $record) {
                $actual[] = $record->fingerprint;
            }
        }
        self::assertSame($expected, $actual);
        self::assertCount(4, array_unique($actual));
        self::assertSame('NEWEST', $repository->search(search: '', offset: 0, limit: 1)[0]->permit->id);
        self::assertSame('A%_!', $repository->search(search: '%_!', offset: 0, limit: 2)[0]->permit->id);
        self::assertCount(1, $repository->search(search: '%_!', offset: 0, limit: 2));
    }

    public function testBlacklistPaginationBreaksCollationTiesAndEscapesWildcards(): void
    {
        $repository = new BlacklistRepository($this->connection);
        foreach (['Case', 'case', 'cāse', 'A%_!', 'ABCD'] as $id) {
            $repository->add($id);
        }
        $expected = $this->connection->fetchFirstColumn(
            'SELECT permit_id FROM blacklisted_ids ORDER BY permit_id, id_hash',
        );
        $actual = [];
        for ($offset = 0; $offset < count($expected); $offset += 2) {
            foreach ($repository->search(search: '', offset: $offset, limit: 2) as $item) {
                $actual[] = $item->id;
            }
        }
        self::assertSame($expected, $actual);
        self::assertSame('A%_!', $repository->search(search: '%_!', offset: 0, limit: 2)[0]->id);
        self::assertCount(1, $repository->search(search: '%_!', offset: 0, limit: 2));
    }

    public function testHistoryRoundTripsEnumsAndUsesAutoIncrementForLatestFirstPagination(): void
    {
        $repository = new PermitCheckRepository($this->connection);
        $checkedAt = new \DateTimeImmutable('2026-11-03T12:00:00+02:00');
        foreach ([PermitCheckOutcome::Valid, PermitCheckOutcome::Rejected, PermitCheckOutcome::Valid] as $outcome) {
            $repository->record(new PermitCheckRecord(
                permitId: 'A%_!🅿',
                idTruncated: false,
                method: 'GET',
                path: '/p/check.php',
                outcome: $outcome,
                reason: PermitCheckOutcome::Rejected === $outcome ? PermitFailureReason::Revoked : null,
                status: 200,
                checkedAt: $checkedAt,
            ));
        }
        $rows = $repository->search(id: '', result: null, offset: 0, limit: 2);
        $next = $repository->search(id: '', result: null, offset: 2, limit: 2);
        self::assertSame(['3', '2', '1'], array_map(
            static fn(PermitCheckRecord $record): ?string => $record->id,
            [...$rows, ...$next],
        ));
        self::assertSame('A%_!🅿', $rows[0]->permitId);
        self::assertFalse($rows[0]->idTruncated);
        self::assertSame('2026-11-03T10:00:00+00:00', $rows[0]->checkedAt->format(DATE_ATOM));
        self::assertSame(PermitFailureReason::Revoked, $rows[1]->reason);
        self::assertSame(PermitCheckOutcome::Rejected, $rows[1]->outcome);
        self::assertCount(2, $repository->search(id: '%_!', result: PermitCheckOutcome::Valid, offset: 0, limit: 5));
        self::assertSame([], $repository->search(id: 'absent', result: null, offset: 0, limit: 5));
    }

    public function testSessionAndLoginAttemptStorageUsesTheMysqlColumnTypes(): void
    {
        $sessions = new AdminSessionRepository($this->connection);
        $session = new AdminSession(
            token: 'synthetic-token',
            csrfToken: str_repeat('a', 64),
            authenticated: true,
            expiresAt: 9999999999,
            credentialHash: str_repeat('b', 64),
        );
        $sessions->save($session, 100);
        self::assertSame((array) $session, (array) $sessions->find($session->token));
        self::assertSame(
            hash(algo: 'sha256', data: $session->token),
            $this->connection->fetchOne('SELECT token_hash FROM admin_sessions'),
        );
        $sessions->remove($session->token);
        self::assertNull($sessions->find($session->token));
        $attempts = new LoginAttemptStore($this->connection);
        $address = str_repeat('c', 64);
        $attempts->fail(addressHash: $address, now: 100, window: 900);
        $attempts->fail(addressHash: $address, now: 101, window: 900);
        self::assertSame(['attempts' => 2, 'startedAt' => 100], $attempts->find($address));
        $attempts->fail(addressHash: $address, now: 1000, window: 900);
        self::assertSame(['attempts' => 1, 'startedAt' => 1000], $attempts->find($address));
        $attempts->clear($address);
        self::assertNull($attempts->find($address));
    }

    public function testHistoryPruningUsesBoundedMysqlBatchesAndPreservesTheCutoff(): void
    {
        $settings = $this->connection->fetchAllAssociative('SELECT * FROM app_settings');
        $checks = new PermitCheckRepository($this->connection);
        foreach (['2026-10-03', '2026-11-01', '2026-10-01', '2026-10-02', '2026-11-02'] as $date) {
            $checks->record(new PermitCheckRecord(
                permitId: $date,
                idTruncated: false,
                method: 'GET',
                path: '/p/check',
                outcome: PermitCheckOutcome::Valid,
                reason: null,
                status: 200,
                checkedAt: new \DateTimeImmutable($date . 'T00:00:00Z'),
                expiresOn: new \DateTimeImmutable('2099-12-31'),
                apartment: '12',
            ));
        }
        $history = $checks->search(id: '2026-10-03', result: null, offset: 0, limit: 1);
        self::assertSame('2099-12-31', $history[0]->expiresOn?->format('Y-m-d'));
        self::assertSame('12', $history[0]->apartment);
        $pruner = new PermitCheckPruner($this->connection);
        self::assertSame(2, $pruner->countBefore(cutoff: '2026-11-01 00:00:00', limit: 2));
        self::assertSame(2, $pruner->deleteBefore(cutoff: '2026-11-01 00:00:00', limit: 2));
        self::assertSame(
            ['2026-10-03', '2026-11-01', '2026-11-02'],
            $this->connection->fetchFirstColumn('SELECT permit_id FROM permit_checks ORDER BY id'),
        );
        self::assertSame(1, $pruner->deleteBefore(cutoff: '2026-11-01 00:00:00', limit: 2));
        self::assertSame(0, $pruner->deleteBefore(cutoff: '2026-11-01 00:00:00', limit: 2));
        self::assertSame(0, $pruner->countBefore(cutoff: '2026-11-01 00:00:00', limit: 1));
        self::assertSame($settings, $this->connection->fetchAllAssociative('SELECT * FROM app_settings'));
    }

    public function testResetCommitsAllChangesTogether(): void
    {
        $blacklist = new BlacklistRepository($this->connection);
        $blacklist->add('temporary');
        $this->connection->executeStatement('DELETE FROM app_settings');
        new DevelopmentDatabaseResetter($this->connection)->reset($this->initialData());
        self::assertSame(6, $this->connection->fetchOne('SELECT COUNT(*) FROM app_settings'));
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        self::assertFalse($blacklist->contains('temporary'));
        self::assertTrue($blacklist->contains('DEMO-BLOCKED-1'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testResetWithoutAnExplicitInitialKeyKeepsTheStoredHmacKey(): void
    {
        /** @psalm-var mixed $key */
        $key = $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
        );
        $data = $this->initialData();
        unset($data['settings']['hmac_signing_key']);
        new DevelopmentDatabaseResetter($this->connection)->reset($data);
        self::assertSame($key, $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
        ));
        self::assertSame(6, $this->connection->fetchOne('SELECT COUNT(*) FROM app_settings'));
    }

    public function testSeedConstraintFailureRollsBackEveryResetDeletion(): void
    {
        new BlacklistRepository($this->connection)->add(id: 'temporary', reason: 'keep me');
        $settings = $this->connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings');
        $data = $this->initialData();
        $data['blacklisted_ids'][] = $data['blacklisted_ids'][0];
        $this->expectException(UniqueConstraintViolationException::class);
        try {
            new DevelopmentDatabaseResetter($this->connection)->reset($data);
        } finally {
            self::assertSame(
                $settings,
                $this->connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings'),
            );
            self::assertTrue(new BlacklistRepository($this->connection)->contains('temporary'));
            self::assertSame(3, $this->connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    /** @return array{settings: array<string, string>, blacklisted_ids: list<string>} */
    private function initialData(): array
    {
        /** @var array{settings: array<string, string>, blacklisted_ids: list<string>} $data */
        $data = require __DIR__ . '/../../config/initial-data.php';

        return $data;
    }
}
