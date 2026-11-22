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
final class DoctorTest extends TestCase
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
        $this->connection->delete('doctrine_migration_versions', [
            'version' => 'Database\\Migrations\\Version20000101000000',
        ]);
        $this->connection->close();
    }

    public function testActualCliChecksMysqlWithoutSeedCredentialsOrChangingAnyRows(): void
    {
        $before = $this->snapshot();
        [$status, $output] = $this->invokeDoctor();
        self::assertSame(0, $status, $output);
        self::assertSame(9, substr_count(haystack: $output, needle: ' OK '));
        self::assertSame($before, $this->snapshot());
        self::assertStringNotContainsString('test-secret', $output);
        self::assertStringNotContainsString(str_repeat('68', 32), $output);
        self::assertStringNotContainsString('DEMO-BLOCKED', $output);
    }

    public function testUnexpectedMigrationHistoryIsReportedWithoutReconciliation(): void
    {
        $this->connection->insert('doctrine_migration_versions', [
            'version' => 'Database\\Migrations\\Version20000101000000',
        ]);
        $before = $this->snapshot();
        [$status, $output] = $this->invokeDoctor();
        self::assertSame(1, $status);
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Migrations\s+/m', $output);
        self::assertSame($before, $this->snapshot());
        self::assertStringNotContainsString('Version20000101000000', $output);
    }

    public function testUnavailableMysqlFailsSafelyEvenWithVerboseOutput(): void
    {
        [$status, $output] = $this->invokeDoctor(['DB_PORT' => '1', 'DB_PASSWORD' => 'private-database-password']);
        self::assertSame(1, $status);
        self::assertSame(3, substr_count(haystack: $output, needle: ' SKIP '));
        self::assertSame(5, substr_count(haystack: $output, needle: ' OK '));
        self::assertStringNotContainsString('private-database-password', $output);
        self::assertStringNotContainsString('SQLSTATE', $output);
        self::assertStringNotContainsString('/var/www', $output);
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function invokeDoctor(array $environment = []): array
    {
        $output = tmpfile();
        self::assertIsResource($output);
        $variables = $environment + [
            'DB_HOST' => 'mysql-test', 'DB_PORT' => '3306', 'DB_DATABASE' => 'permits_test',
            'DB_USERNAME' => 'permits_test', 'DB_PASSWORD' => 'permits_test',
            'PUBLIC_UI_CONFIG' => 'config/public-ui.example.php',
            'PUBLIC_TEMPLATE_DIRECTORY' => 'templates',
            'CHECK_HISTORY_LOCK_FILE' => '/tmp/doctor-test-unused/history.lock',
        ];
        $variables += array_filter(
            getenv(),
            static fn(string $name): bool => !str_starts_with(haystack: $name, needle: 'INITIAL_'),
            ARRAY_FILTER_USE_KEY,
        );
        try {
            $process = proc_open(
                command: [PHP_BINARY, 'bin/console.php', 'app:doctor', '--no-interaction', '--no-ansi', '-vvv'],
                descriptor_spec: [1 => $output, 2 => $output],
                pipes: $pipes,
                cwd: __DIR__ . '/../..',
                env_vars: $variables,
            );
            self::assertIsResource($process);
            $status = proc_close($process);
            rewind($output);

            return [$status, (string) stream_get_contents($output)];
        } finally {
            fclose($output);
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $rows = [];
        foreach ($this->connection->createSchemaManager()->introspectTableNames() as $name) {
            $table = $name->getUnqualifiedName()->getValue();
            $rows[$table] = $this->connection->fetchAllAssociative(
                'SELECT * FROM ' . $name->toSQL($this->connection->getDatabasePlatform()),
            );
        }

        return $rows;
    }
}
