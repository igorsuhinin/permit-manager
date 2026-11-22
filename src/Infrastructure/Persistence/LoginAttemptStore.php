<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\LoginAttemptStoreInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

final readonly class LoginAttemptStore implements LoginAttemptStoreInterface
{
    public function __construct(private Connection $connection) {}

    /** @return array{attempts: int, startedAt: int}|null */
    #[\Override]
    public function find(string $addressHash): ?array
    {
        /** @var array{attempts: int|string, window_started: int|string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT attempts, window_started FROM admin_login_attempts WHERE ip_hash = ?',
            [$addressHash],
        );

        return false === $row ? null : [
            'attempts' => (int) $row['attempts'],
            'startedAt' => (int) $row['window_started'],
        ];
    }

    #[\Override]
    public function fail(string $addressHash, int $now, int $window): void
    {
        $this->connection->executeStatement(
            'DELETE FROM admin_login_attempts WHERE window_started <= ? AND ip_hash <> ?',
            [$now - $window, $addressHash],
        );
        $update = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE '
            : ' ON CONFLICT (ip_hash) DO UPDATE SET ';
        $this->connection->executeStatement(
            'INSERT INTO admin_login_attempts (ip_hash, attempts, window_started) VALUES (?, 1, ?)'
                . $update . 'attempts = CASE WHEN window_started <= ? THEN 1 ELSE attempts + 1 END,'
                . ' window_started = CASE WHEN window_started <= ? THEN ? ELSE window_started END',
            [$addressHash, $now, $now - $window, $now - $window, $now],
        );
    }

    #[\Override]
    public function clear(string $addressHash): void
    {
        $this->connection->delete('admin_login_attempts', ['ip_hash' => $addressHash]);
    }
}
