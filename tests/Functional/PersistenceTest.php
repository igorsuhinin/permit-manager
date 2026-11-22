<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Permit\Model\Permit;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\DevelopmentDatabaseResetter;
use App\Infrastructure\Persistence\PermitRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Support\Database;
use Tests\Support\InitialSettingsFixture;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class PersistenceTest extends TestCase
{
    public function testMigrationInitializesSettingsAndConfiguredBlacklist(): void
    {
        $connection = Database::create();
        $settings = new SettingsRepository($connection)->load();
        self::assertSame('test-secret', $settings->secret);
        self::assertTrue(password_verify(password: 'test-password', hash: $settings->passwordHash));
        self::assertSame('https://example.com', $settings->baseUrl);
        self::assertSame('Europe/Riga', $settings->timezone);
        self::assertSame('admin', new SettingsRepository($connection)->adminUsername());
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $blacklist = new BlacklistRepository($connection);
        self::assertTrue($blacklist->contains('DEMO-BLOCKED-1'));
        self::assertTrue($blacklist->contains('DEMO-BLOCKED-2'));
        self::assertFalse($blacklist->contains('48'));
    }

    public function testBlacklistDuplicatePreservesReason(): void
    {
        $connection = Database::create();
        $blacklist = new BlacklistRepository($connection);
        $blacklist->add(id: 'test', reason: 'original reason');
        $blacklist->add(id: 'test', reason: 'changed reason');
        self::assertSame(
            'original reason',
            $connection->fetchOne(
                'SELECT reason FROM blacklisted_ids WHERE id_hash = ?',
                [hash(algo: 'sha256', data: 'test')],
            ),
        );
    }

    public function testResetReplaysInitialDataMigrationAndClearsTemporaryRecords(): void
    {
        $connection = Database::create();
        $original = new SettingsRepository($connection)->load();
        $blacklist = new BlacklistRepository($connection);
        $blacklist->remove('DEMO-BLOCKED-1');
        $blacklist->add('temporary');
        $connection->update(
            table: 'app_settings',
            data: ['setting_value' => 'changed-admin'],
            criteria: ['setting_key' => 'admin_username'],
        );
        $connection->update(
            table: 'app_settings',
            data: ['setting_value' => 'https://changed.example.com'],
            criteria: ['setting_key' => 'base_url'],
        );
        new PermitRepository($connection)->save(
            new Permit(id: 'temporary', term: '31.12.2099', plate: 'TEST'),
            new \DateTimeImmutable('2099-12-31'),
            new \DateTimeImmutable(),
        );
        $connection->insert(
            'admin_sessions',
            [
                'token_hash' => str_repeat('a', 64),
                'csrf_token' => str_repeat('b', 64),
                'authenticated' => 1,
                'expires_at' => 9999999999,
                'credential_hash' => 'test',
            ],
        );
        $connection->insert(
            'admin_login_attempts',
            ['ip_hash' => str_repeat('c', 64), 'attempts' => 1, 'window_started' => 1],
        );
        $connection->insert(
            'permit_checks',
            [
                'permit_id' => 'temporary',
                'id_truncated' => 0,
                'method' => 'GET',
                'path' => '/p/check',
                'result' => 'valid',
                'reason' => null,
                'status' => 200,
                'checked_at' => '2026-11-03 10:00:00',
            ],
        );
        new DevelopmentDatabaseResetter($connection)->reset($this->initialData());
        $restored = new SettingsRepository($connection)->load();
        self::assertSame($original->secret, $restored->secret);
        self::assertSame($original->passwordHash, $restored->passwordHash);
        self::assertSame($original->baseUrl, $restored->baseUrl);
        self::assertSame($original->timezone, $restored->timezone);
        self::assertSame('admin', new SettingsRepository($connection)->adminUsername());
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        self::assertTrue($blacklist->contains('DEMO-BLOCKED-1'));
        self::assertFalse($blacklist->contains('temporary'));
        foreach (['permits', 'permit_checks', 'admin_sessions', 'admin_login_attempts'] as $table) {
            self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
    }

    public function testFailedResetPreservesExistingData(): void
    {
        $connection = Database::create();
        $settings = $connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Provide a valid admin password hash.');
        try {
            InitialSettingsFixture::withConfiguration(['password_hash' => 'invalid-hash'], function () use (
                $connection,
            ): void {
                new DevelopmentDatabaseResetter($connection)->reset($this->initialData());
            });
        } finally {
            self::assertSame(
                $settings,
                $connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings'),
            );
            self::assertTrue(new BlacklistRepository($connection)->contains('DEMO-BLOCKED-1'));
        }
    }

    public function testSeedWriteFailureRollsBackAllResetDeletions(): void
    {
        $connection = Database::create();
        $settings = $connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings');
        $connection->executeStatement(
            'CREATE TRIGGER reject_seed BEFORE INSERT ON app_settings '
            . "BEGIN SELECT RAISE(FAIL, 'seed write failure'); END",
        );
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        try {
            new DevelopmentDatabaseResetter($connection)->reset($this->initialData());
        } finally {
            self::assertSame(
                $settings,
                $connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings'),
            );
            self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        }
    }

    public function testMissingConfigurationIsRejected(): void
    {
        $connection = Database::create(false);
        $this->expectException(\RuntimeException::class);
        new SettingsRepository($connection)->load();
    }

    #[DataProvider('invalidHmacKeys')]
    public function testInvalidStoredHmacKeysCannotBeUsedForIssuance(string $key): void
    {
        $connection = Database::create();
        $connection->update(
            table: 'app_settings',
            data: ['setting_value' => $key],
            criteria: ['setting_key' => 'hmac_signing_key'],
        );
        $this->expectException(\RuntimeException::class);
        new SettingsRepository($connection)->load();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHmacKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 63)];
        yield 'non-hex' => [str_repeat('g', 64)];
    }

    public function testResetRestoresTheConfiguredSigningKey(): void
    {
        $connection = Database::create();
        $original = new SettingsRepository($connection)->load();
        self::assertSame(32, strlen($original->hmacKey));
        new DevelopmentDatabaseResetter($connection)->reset($this->initialData());
        self::assertSame($original->hmacKey, new SettingsRepository($connection)->load()->hmacKey);
        self::assertSame(6, $connection->fetchOne('SELECT COUNT(*) FROM app_settings'));
    }

    public function testResetWithoutAnInitialOrStoredKeyFailsBeforeDeletingData(): void
    {
        $connection = Database::create();
        $connection->delete('app_settings', ['setting_key' => 'hmac_signing_key']);
        $data = $this->initialData();
        unset($data['settings']['hmac_signing_key']);
        $before = $connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('The HMAC key is missing or invalid. Run database migrations.');
        try {
            new DevelopmentDatabaseResetter($connection)->reset($data);
        } finally {
            self::assertSame(
                $before,
                $connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings'),
            );
            self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
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
