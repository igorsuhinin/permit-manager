<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class DevelopmentDatabaseResetter
{
    public function __construct(private Connection $connection) {}

    /** @param array{settings: array<string, string>, blacklisted_ids: list<string>} $data */
    public function reset(array $data): void
    {
        $this->connection->transactional(function () use ($data): void {
            $settings = $data['settings'];
            if (!isset($settings['hmac_signing_key'])) {
                $key = $this->connection->fetchOne(
                    "SELECT setting_value FROM app_settings WHERE setting_key = 'hmac_signing_key'",
                );
                if (!is_string($key) || 1 !== preg_match(pattern: '/\A[0-9a-fA-F]{64}\z/', subject: $key)) {
                    throw new \RuntimeException('The HMAC key is missing or invalid. Run database migrations.');
                }
                $settings['hmac_signing_key'] = $key;
            }
            foreach (
                [
                    'permit_checks',
                    'admin_audit',
                    'admin_sessions',
                    'admin_login_attempts',
                    'permits',
                    'blacklisted_ids',
                    'app_settings',
                ] as $table
            ) {
                $this->connection->executeStatement('DELETE FROM ' . $table);
            }
            foreach ($settings as $key => $value) {
                $this->connection->insert('app_settings', ['setting_key' => $key, 'setting_value' => $value]);
            }
            foreach ($data['blacklisted_ids'] as $id) {
                $this->connection->insert(
                    'blacklisted_ids',
                    ['id_hash' => hash(algo: 'sha256', data: $id), 'permit_id' => $id],
                );
            }
        });
    }
}
