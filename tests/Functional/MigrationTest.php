<?php

declare(strict_types=1);

namespace Tests\Functional;

use Database\Migrations\Version20261105000000;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\Database;
use Tests\Support\InitialSettingsFixture;

#[Medium]
#[CoversClass(Version20261105000000::class)]
#[UsesNamespace('App')]
final class MigrationTest extends TestCase
{
    public function testCheckHistorySupportsRowsWithoutOptionalPermitDetails(): void
    {
        $connection = Database::create();
        $connection->insert('permit_checks', [
            'permit_id' => 'legacy', 'id_truncated' => 0, 'method' => 'GET', 'path' => '/p/check',
            'result' => 'valid', 'status' => 200, 'checked_at' => '2026-11-03 10:00:00',
        ]);
        self::assertSame(
            ['permit_id' => 'legacy', 'expires_on' => null, 'apartment' => null],
            $connection->fetchAssociative('SELECT permit_id, expires_on, apartment FROM permit_checks'),
        );
    }

    public function testFreshMigrationsIncludeInitialData(): void
    {
        $connection = Database::create();
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
        self::assertSame(
            'test-secret',
            $connection->fetchOne("SELECT setting_value FROM app_settings WHERE setting_key = 'signing_secret'"),
        );
        self::assertSame(
            'admin',
            $connection->fetchOne("SELECT setting_value FROM app_settings WHERE setting_key = 'admin_username'"),
        );
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM admin_audit'));
        self::assertSame(str_repeat('68', 32), $connection->fetchOne(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
        ));
        $migration = new Version20261105000000($connection, new NullLogger());
        self::assertFalse($migration->isTransactional());
        self::assertStringContainsString('complete application schema', $migration->getDescription());
    }

    #[DataProvider('invalidInitialData')]
    public function testInvalidInitialDataIsRejectedBeforeSchemaSqlIsQueued(
        string $key,
        string $value,
        string $message,
    ): void {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261105000000($connection, new NullLogger());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);
        try {
            InitialSettingsFixture::withConfiguration(
                [$key => $value],
                static function () use ($migration, $connection): void {
                    $migration->up($connection->createSchemaManager()->introspectSchema());
                },
            );
        } finally {
            self::assertSame([], $migration->getSql());
            self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'"));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInitialData(): iterable
    {
        yield 'password hash' => ['password_hash', 'invalid', 'Provide a valid admin password hash.'];
        yield 'URL' => ['base_url', 'invalid',
            'Provide an HTTP(S) base URL without credentials, query, or fragment.'];
        foreach ([63 => 'a', 65 => 'a', 64 => 'g'] as $length => $character) {
            yield 'HMAC key ' . $length => [
                'hmac_signing_key', str_repeat($character, $length),
                'The HMAC signing key must contain exactly 64 hexadecimal characters.',
            ];
        }
        yield 'HMAC key newline' => [
            'hmac_signing_key', str_repeat('a', 64) . "\n",
            'The HMAC signing key must contain exactly 64 hexadecimal characters.',
        ];
    }

    public function testEmptyPrivateBlacklistCreatesAnEmptyInitialBlacklist(): void
    {
        $previous = getenv('INITIAL_BLACKLIST_CONFIG');
        putenv('INITIAL_BLACKLIST_CONFIG=config/initial-blacklist.example.php');
        try {
            $connection = Database::create();
            self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM blacklisted_ids'));
            self::assertSame(6, $connection->fetchOne('SELECT COUNT(*) FROM app_settings'));
        } finally {
            putenv(false === $previous ? 'INITIAL_BLACKLIST_CONFIG' : 'INITIAL_BLACKLIST_CONFIG=' . $previous);
        }
    }

    #[DataProvider('invalidBlacklist')]
    public function testInvalidPrivateBlacklistIsRejectedBeforeSchemaSqlIsQueued(
        string $contents,
        string $message,
    ): void {
        $previous = getenv('INITIAL_BLACKLIST_CONFIG');
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'initial-blacklist-');
        self::assertNotFalse($path);
        file_put_contents(filename: $path, data: '<?php return ' . $contents . ';');
        putenv('INITIAL_BLACKLIST_CONFIG=' . $path);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261105000000($connection, new NullLogger());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs($message);
        try {
            $migration->up($connection->createSchemaManager()->introspectSchema());
        } finally {
            putenv(false === $previous ? 'INITIAL_BLACKLIST_CONFIG' : 'INITIAL_BLACKLIST_CONFIG=' . $previous);
            unlink($path);
            self::assertSame([], $migration->getSql());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBlacklist(): iterable
    {
        yield 'non-list' => ["['key' => 'DEMO-001']", 'Initial blacklist must be a list of non-empty permit IDs.'];
        yield 'non-string' => ['[123]', 'Initial blacklist must be a list of non-empty permit IDs.'];
        yield 'empty' => ["['   ']", 'Initial blacklist must be a list of non-empty permit IDs.'];
        yield 'duplicate' => ["['DEMO-001', 'DEMO-001']", 'Initial blacklist must not contain duplicate IDs.'];
    }

    public function testMigrationCreatesAndRemovesTheApplicationTables(): void
    {
        $connection = Database::create(false);
        self::assertSame(
            ['admin_audit', 'admin_login_attempts', 'admin_sessions', 'app_settings', 'blacklisted_ids',
                'permit_checks', 'permits'],
            $connection->fetchFirstColumn(
                "SELECT name FROM sqlite_master WHERE name != 'sqlite_sequence' AND type = 'table' ORDER BY name",
            ),
        );
        $migration = new Version20261105000000($connection, new NullLogger());
        $migration->down($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame(
            [],
            $connection->fetchFirstColumn(
                "SELECT name FROM sqlite_master WHERE name != 'sqlite_sequence' AND type = 'table'",
            ),
        );
    }

    public function testFreshMigrationUsesConfiguredUsernameAndTimezone(): void
    {
        InitialSettingsFixture::withConfiguration([
            'admin_username' => 'operator', 'timezone' => 'UTC',
        ], static function (): void {
            $connection = Database::create();
            self::assertSame('operator', $connection->fetchOne(
                "SELECT setting_value FROM app_settings WHERE setting_key = 'admin_username'",
            ));
            self::assertSame('UTC', $connection->fetchOne(
                "SELECT setting_value FROM app_settings WHERE setting_key = 'timezone'",
            ));
        });
    }

    public function testBaselineIncludesAllQueryIndexes(): void
    {
        $connection = Database::create();
        foreach (
            [
                'permit_checks' => [
                    'permit_checks_checked_at' => ['checked_at'], 'permit_checks_result_id' => ['result', 'id'],
                ],
                'admin_sessions' => ['admin_sessions_expires_at' => ['expires_at']],
                'admin_login_attempts' => ['admin_login_attempts_window' => ['window_started']],
                'permits' => ['permits_created_fingerprint' => ['created_at', 'fingerprint']],
                'admin_audit' => ['admin_audit_target' => ['target_hash', 'id']],
            ] as $table => $indexes
        ) {
            $actual = [];
            foreach ($connection->createSchemaManager()->introspectTableIndexesByUnquotedName($table) as $index) {
                $actual[$index->getObjectName()->getIdentifier()->getValue()] = array_map(
                    static fn(IndexedColumn $column): string => $column->getColumnName()->getIdentifier()->getValue(),
                    $index->getIndexedColumns(),
                );
            }
            foreach ($indexes as $name => $columns) {
                self::assertArrayHasKey($name, $actual);
                self::assertSame($columns, $actual[$name]);
            }
        }
    }

    public function testUnversionedStoredRowsDefaultToLegacy(): void
    {
        $connection = Database::create();
        $connection->insert('permits', [
            'fingerprint' => str_repeat('a', 64), 'permit_id' => 'DEMO-OLD', 'plate' => 'AB1234',
            'apartment' => '', 'guest' => 0, 'expires_on' => '2099-12-31', 'created_at' => '2026-11-03 10:00:00',
        ]);
        self::assertSame(1, $connection->fetchOne('SELECT signature_version FROM permits'));
    }
}
