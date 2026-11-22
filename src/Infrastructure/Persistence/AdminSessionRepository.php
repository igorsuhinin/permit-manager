<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Authentication\AdminSession;
use App\Application\Port\AdminSessionRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class AdminSessionRepository implements AdminSessionRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function find(string $token): ?AdminSession
    {
        /**
         * @var array{
         *     csrf_token: string,
         *     authenticated: bool|int|string,
         *     expires_at: int|string,
         *     credential_hash: ?string
         * }|false $row
         */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM admin_sessions WHERE token_hash = ?',
            [hash(algo: 'sha256', data: $token)],
        );
        if (false === $row) {
            return null;
        }

        return new AdminSession(
            token: $token,
            csrfToken: $row['csrf_token'],
            authenticated: (bool) $row['authenticated'],
            expiresAt: (int) $row['expires_at'],
            credentialHash: $row['credential_hash'],
        );
    }

    #[\Override]
    public function save(AdminSession $session, int $now): void
    {
        $this->connection->executeStatement('DELETE FROM admin_sessions WHERE expires_at <= ?', [$now]);
        $this->connection->insert('admin_sessions', [
            'token_hash' => hash(algo: 'sha256', data: $session->token),
            'csrf_token' => $session->csrfToken,
            'authenticated' => (int) $session->authenticated,
            'expires_at' => $session->expiresAt,
            'credential_hash' => $session->credentialHash,
        ]);
    }

    #[\Override]
    public function remove(string $token): void
    {
        $this->connection->delete('admin_sessions', ['token_hash' => hash(algo: 'sha256', data: $token)]);
    }
}
