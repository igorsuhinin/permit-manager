<?php

declare(strict_types=1);

namespace Tests\MySql;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;
use Tests\Support\MySqlDatabase;

#[Medium]
#[CoversNothing]
final class MigrationTest extends TestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        MySqlDatabase::migrate('0');
        $this->connection = MySqlDatabase::connect();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (
            false !== $this->connection->fetchOne(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                ['permits'],
            )
        ) {
            $this->connection->executeStatement('DELETE FROM permits');
        }
        $this->connection->close();
    }

    public function testCliMigrationCreatesTransactionalUtf8SchemaAndSyntheticInitialData(): void
    {
        MySqlDatabase::migrate();
        self::assertSame([
            'admin_audit', 'admin_login_attempts', 'admin_sessions', 'app_settings', 'blacklisted_ids',
            'doctrine_migration_versions', 'permit_checks', 'permits',
        ], $this->connection->fetchFirstColumn('SHOW TABLES'));
        $tables = $this->connection->fetchAllAssociative(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        );
        foreach ($tables as $table) {
            self::assertSame('InnoDB', $table['ENGINE']);
            self::assertIsString($table['TABLE_COLLATION']);
            self::assertStringStartsWith('utf8mb4_', $table['TABLE_COLLATION']);
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
        self::assertSame(6, $this->connection->fetchOne('SELECT COUNT(*) FROM app_settings'));
        self::assertSame(str_repeat('68', 32), $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
        ));
        self::assertSame(
            'test-secret',
            $this->connection->fetchOne("SELECT setting_value FROM app_settings WHERE setting_key = 'signing_secret'"),
        );
        $hash = $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'password_hash'",
        );
        self::assertIsString($hash);
        self::assertTrue(password_verify(password: 'test-password', hash: $hash));
        self::assertSame(['DEMO-BLOCKED-1', 'DEMO-BLOCKED-2'], $this->connection->fetchFirstColumn(
            'SELECT permit_id FROM blacklisted_ids ORDER BY permit_id',
        ));
        foreach (['permits', 'permit_checks', 'admin_sessions', 'admin_login_attempts', 'admin_audit'] as $table) {
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
    }

    public function testMigratingAgainPreservesExistingSettingsAndBlacklistChanges(): void
    {
        MySqlDatabase::migrate();
        $this->connection->update(
            table: 'app_settings',
            data: ['setting_value' => 'https://changed.example.com'],
            criteria: ['setting_key' => 'base_url'],
        );
        $this->connection->executeStatement('DELETE FROM blacklisted_ids');
        MySqlDatabase::migrate();
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        self::assertSame(
            'https://changed.example.com',
            $this->connection->fetchOne("SELECT setting_value FROM app_settings WHERE setting_key = 'base_url'"),
        );
    }

    public function testRollbackRemovesApplicationTablesAndAllowsFreshMigration(): void
    {
        MySqlDatabase::migrate();
        $this->connection->insert('permits', [
            'fingerprint' => str_repeat('b', 64), 'permit_id' => 'DEMO-HMAC', 'plate' => 'TEST',
            'apartment' => '', 'guest' => 0, 'expires_on' => '2099-12-31',
            'created_at' => '2026-11-03 10:00:00', 'signature_version' => 2,
        ]);
        $this->connection->insert('admin_audit', [
            'actor' => 'admin', 'action' => 'permit_issued', 'target_hash' => str_repeat('b', 64),
            'occurred_at' => '2026-11-03 10:00:00',
        ]);
        MySqlDatabase::migrate('0');
        self::assertSame(['doctrine_migration_versions'], $this->connection->fetchFirstColumn('SHOW TABLES'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
        MySqlDatabase::migrate();
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
    }

    public function testRerunPreservesBothPermitVersionsAndKeysWithoutInitialCredentials(): void
    {
        MySqlDatabase::migrate();
        $settings = $this->connection->fetchAllKeyValue('SELECT setting_key, setting_value FROM app_settings');
        $row = [
            'fingerprint' => str_repeat('a', 64), 'permit_id' => 'DEMO-OLD', 'plate' => 'TEST',
            'apartment' => '', 'guest' => 0, 'expires_on' => '2099-12-31', 'created_at' => '2026-11-03 10:00:00',
        ];
        $this->connection->insert('permits', $row);
        $hmac = array_replace($row, ['fingerprint' => str_repeat('b', 64), 'signature_version' => 2]);
        $this->connection->insert('permits', $hmac);
        $previous = getenv('INITIAL_SETTINGS_CONFIG');
        putenv('INITIAL_SETTINGS_CONFIG=/missing-initial-settings.php');
        try {
            MySqlDatabase::migrate();
            self::assertSame($settings, $this->connection->fetchAllKeyValue(
                'SELECT setting_key, setting_value FROM app_settings',
            ));
            self::assertSame([$row + ['signature_version' => 1], $hmac], $this->connection->fetchAllAssociative(
                'SELECT * FROM permits ORDER BY fingerprint',
            ));
            self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
        } finally {
            putenv(false === $previous ? 'INITIAL_SETTINGS_CONFIG' : 'INITIAL_SETTINGS_CONFIG=' . $previous);
        }
    }

    public function testCliRetainsTheConfiguredKeyAcrossMigrationReruns(): void
    {
        MySqlDatabase::migrate();
        $key = $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
        );
        self::assertIsString($key);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $key);
        MySqlDatabase::migrate();
        self::assertSame($key, $this->connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
        ));
    }
}
