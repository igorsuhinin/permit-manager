<?php

declare(strict_types=1);

namespace App\Infrastructure\Diagnostics;

use App\Application\Enum\DiagnosticStatus;
use App\Application\Port\SettingsProviderInterface;
use App\Application\View\DiagnosticCheck;
use Doctrine\DBAL\Connection;

final readonly class DatabaseDiagnostics
{
    private const array COLUMNS = [
        'app_settings' => 'setting_key, setting_value',
        'blacklisted_ids' => 'id_hash, permit_id, reason',
        'permits' => 'fingerprint, permit_id, plate, apartment, guest, expires_on, created_at, signature_version',
        'admin_sessions' => 'token_hash, csrf_token, authenticated, expires_at, credential_hash',
        'admin_login_attempts' => 'ip_hash, attempts, window_started',
        'permit_checks' => 'id, permit_id, id_truncated, method, path, result, reason, status, checked_at',
        'admin_audit' => 'id, actor, action, target_hash, occurred_at',
    ];

    private const array INDEXES = [
        'permit_checks' => ['permit_checks_checked_at', 'permit_checks_result_id'],
        'permits' => ['permits_created_fingerprint'],
        'admin_sessions' => ['admin_sessions_expires_at'],
        'admin_login_attempts' => ['admin_login_attempts_window'],
        'admin_audit' => ['admin_audit_target'],
    ];

    public function __construct(
        private Connection $connection,
        private SettingsProviderInterface $settings,
        private string $migrationDirectory,
    ) {}

    /** @return list<DiagnosticCheck> */
    public function inspect(): array
    {
        $database = $this->check(
            name: 'Database',
            probe: function (): string {
                $this->connection->fetchOne('SELECT 1');

                return 'Connection and read query succeeded.';
            },
            failure: 'Cannot query the database. Verify CLI database configuration and availability.',
        );
        if (DiagnosticStatus::Failed === $database->status) {
            $checks = [$database];
            foreach (['Schema', 'Migrations', 'Settings'] as $name) {
                $checks[] = new DiagnosticCheck(
                    name: $name,
                    status: DiagnosticStatus::Skipped,
                    message: 'Database is unavailable.',
                );
            }

            return $checks;
        }

        return [
            $database,
            $this->check(
                name: 'Schema',
                probe: $this->schema(...),
                failure: 'Required tables, columns, or indexes are missing or unreadable. Review the schema.',
            ),
            $this->check(
                name: 'Migrations',
                probe: $this->migrations(...),
                failure: 'Migration history does not match this release. Review migration status privately.',
            ),
            $this->check(
                name: 'Settings',
                probe: function (): string {
                    $this->settings->load();
                    $this->settings->adminUsername();

                    return 'Signing keys, credentials, URL, and timezone are valid.';
                },
                failure: 'Stored settings are incomplete or invalid. Review them privately; retain existing keys.',
            ),
        ];
    }

    private function schema(): string
    {
        foreach (self::COLUMNS as $table => $columns) {
            $this->connection->executeQuery('SELECT ' . $columns . ' FROM ' . $table . ' WHERE 1 = 0')->free();
        }
        $manager = $this->connection->createSchemaManager();
        foreach (self::INDEXES as $table => $required) {
            $names = [];
            foreach ($manager->introspectTableIndexesByUnquotedName($table) as $index) {
                $names[] = $index->getObjectName()->getIdentifier()->getValue();
            }
            if ([] !== array_diff($required, $names)) {
                throw new \RuntimeException('Missing required index.');
            }
        }

        return 'Required tables, columns, and named indexes are present.';
    }

    private function migrations(): string
    {
        $files = glob($this->migrationDirectory . '/Version*.php');
        if (false === $files || [] === $files) {
            throw new \RuntimeException('Migration files are unavailable.');
        }
        $expected = array_map(
            static fn(string $file): string => 'Database\\Migrations\\' . basename(path: $file, suffix: '.php'),
            $files,
        );
        /** @var list<string> $actual */
        $actual = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        if ([] !== array_diff($expected, $actual) || [] !== array_diff($actual, $expected)) {
            throw new \RuntimeException('Migration history differs from release.');
        }

        return 'All release migrations are recorded; no unavailable versions.';
    }

    /** @param \Closure(): string $probe */
    private function check(string $name, \Closure $probe, string $failure): DiagnosticCheck
    {
        try {
            return new DiagnosticCheck(name: $name, status: DiagnosticStatus::Passed, message: $probe());
        } catch (\Throwable) {
            return new DiagnosticCheck(name: $name, status: DiagnosticStatus::Failed, message: $failure);
        }
    }
}
