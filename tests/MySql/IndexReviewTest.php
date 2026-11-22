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
final class IndexReviewTest extends TestCase
{
    public function testMaintenanceAndListingPlansWithRepresentativeData(): void
    {
        MySqlDatabase::migrate('0');
        MySqlDatabase::migrate();
        $connection = MySqlDatabase::connect();
        try {
            $this->seed($connection);
            $after = $this->plans($connection);
            // Compare plans for the same synthetic data with and without the baseline indexes.
            $indexes = [
                'permit_checks_checked_at' => ['permit_checks', 'checked_at'],
                'admin_sessions_expires_at' => ['admin_sessions', 'expires_at'],
                'admin_login_attempts_window' => ['admin_login_attempts', 'window_started'],
                'permits_created_fingerprint' => ['permits', 'created_at DESC, fingerprint ASC'],
            ];
            foreach ($indexes as $name => [$table]) {
                $connection->executeStatement('DROP INDEX ' . $name . ' ON ' . $table);
            }
            $before = $this->plans($connection);
            foreach ($indexes as $name => [$table, $columns]) {
                $connection->executeStatement('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $columns . ')');
            }
            foreach (
                [
                    'history' => 'permit_checks_checked_at', 'sessions' => 'admin_sessions_expires_at',
                    'attempts' => 'admin_login_attempts_window', 'permits' => 'permits_created_fingerprint',
                ] as $query => $index
            ) {
                self::assertSame($index, $after[$query][0]['key']);
                $extra = $after[$query][0]['Extra'] ?? '';
                self::assertIsString($extra);
                self::assertStringNotContainsString('filesort', $extra);
            }
            $directory = __DIR__ . '/../../.phpunit.cache/mysql';
            if (!is_dir($directory)) {
                mkdir(directory: $directory, permissions: 0770, recursive: true);
            }
            file_put_contents(filename: $directory . '/index-review.json', data: json_encode(
                value: ['rows_per_table' => 5000, 'before' => $before, 'after' => $after],
                flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
            self::assertSame('ALL', $before['history'][0]['type']);
            self::assertSame('ALL', $before['sessions'][0]['type']);
            self::assertIsString($before['permits'][0]['Extra']);
            self::assertStringContainsString('filesort', $before['permits'][0]['Extra']);
        } finally {
            $connection->executeStatement('DELETE FROM permits');
            $connection->close();
        }
    }

    private function seed(Connection $connection): void
    {
        $connection->transactional(static function () use ($connection): void {
            for ($row = 0; $row < 5000; ++$row) {
                $hash = hash(algo: 'sha256', data: (string) $row);
                $date = new \DateTimeImmutable('2020-01-01')->modify('+' . $row . ' days')->format('Y-m-d H:i:s');
                $connection->insert('permit_checks', [
                    'permit_id' => 'DEMO-' . $row, 'id_truncated' => 0, 'method' => 'GET', 'path' => '/p/check',
                    'result' => 'valid', 'reason' => null, 'status' => 200, 'checked_at' => $date,
                ]);
                $connection->insert('admin_sessions', [
                    'token_hash' => $hash, 'csrf_token' => $hash, 'authenticated' => 0,
                    'expires_at' => $row, 'credential_hash' => null,
                ]);
                $connection->insert(
                    'admin_login_attempts',
                    ['ip_hash' => $hash, 'attempts' => 1, 'window_started' => $row],
                );
                $connection->insert('permits', [
                    'fingerprint' => $hash, 'permit_id' => 'DEMO-' . $row, 'plate' => 'TEST', 'apartment' => '',
                    'guest' => 0, 'expires_on' => '2099-12-31', 'created_at' => $date, 'signature_version' => 1,
                ]);
            }
        });
        $connection->fetchAllAssociative('ANALYZE TABLE permit_checks, admin_sessions, admin_login_attempts, permits');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function plans(Connection $connection): array
    {
        $queries = [
            'history' => "SELECT id FROM permit_checks WHERE checked_at < '2020-02-01'"
                . ' ORDER BY checked_at, id LIMIT 500',
            'sessions' => 'DELETE FROM admin_sessions WHERE expires_at <= 100',
            'attempts' => 'DELETE FROM admin_login_attempts WHERE window_started <= 100',
            'permits' => 'SELECT * FROM permits ORDER BY created_at DESC, fingerprint ASC LIMIT 21',
        ];
        $plans = [];
        foreach ($queries as $name => $sql) {
            $plans[$name] = $connection->fetchAllAssociative('EXPLAIN ' . $sql);
        }

        return $plans;
    }
}
