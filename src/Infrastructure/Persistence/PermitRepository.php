<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\PermitLookupInterface;
use App\Application\Port\PermitQueriesInterface;
use App\Application\View\PermitRecord;
use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Port\PermitRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class PermitRepository implements
    PermitRepositoryInterface,
    PermitQueriesInterface,
    PermitLookupInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function find(string $fingerprint): ?Permit
    {
        /**
         * @var array{
         *     permit_id: string,
         *     plate: string,
         *     apartment: string,
         *     guest: bool|int|string,
         *     expires_on: string,
         *     signature_version: int|string
         * }|false $row
         */
        $row = $this->connection->fetchAssociative(
            'SELECT permit_id, plate, apartment, guest, expires_on, signature_version FROM permits'
                . ' WHERE fingerprint = ?',
            [$fingerprint],
        );

        return false === $row ? null : new Permit(
            id: $row['permit_id'],
            term: new \DateTimeImmutable($row['expires_on'])->format('d.m.Y'),
            plate: $row['plate'],
            guest: (bool) $row['guest'],
            apartment: $row['apartment'],
            signatureVersion: PermitSignatureVersion::from((int) $row['signature_version']),
        );
    }

    /** @return list<PermitRecord> */
    #[\Override]
    public function search(string $search, int $offset, int $limit): array
    {
        $sql = 'SELECT * FROM permits';
        $parameters = [];
        if ('' !== $search) {
            $sql .= " WHERE permit_id LIKE ? ESCAPE '!' OR plate LIKE ? ESCAPE '!' OR apartment LIKE ? ESCAPE '!'";
            $pattern = '%' . str_replace(search: ['!', '%', '_'], replace: ['!!', '!%', '!_'], subject: $search) . '%';
            $parameters = [$pattern, $pattern, $pattern];
        }
        $sql .= ' ORDER BY created_at DESC, fingerprint ASC';
        /**
         * @var list<array{
         *     fingerprint: string,
         *     permit_id: string,
         *     plate: string,
         *     apartment: string,
         *     guest: bool|int|string,
         *     expires_on: string,
         *     created_at: string,
         *     signature_version: int|string
         * }> $rows
         */
        $rows = $this->connection->fetchAllAssociative(
            $this->connection->getDatabasePlatform()->modifyLimitQuery(query: $sql, limit: $limit, offset: $offset),
            $parameters,
        );

        return array_map(static fn(array $row): PermitRecord => new PermitRecord(
            fingerprint: $row['fingerprint'],
            permit: new Permit(
                id: $row['permit_id'],
                term: new \DateTimeImmutable($row['expires_on'])->format('d.m.Y'),
                plate: $row['plate'],
                guest: (bool) $row['guest'],
                apartment: $row['apartment'],
                signatureVersion: PermitSignatureVersion::from((int) $row['signature_version']),
            ),
            expiresOn: new \DateTimeImmutable($row['expires_on']),
            createdAt: new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')),
        ), $rows);
    }

    #[\Override]
    public function save(Permit $permit, \DateTimeImmutable $expiration, \DateTimeImmutable $createdAt): bool
    {
        try {
            $this->connection->insert('permits', [
                'fingerprint' => $permit->fingerprint(),
                'permit_id' => $permit->id,
                'plate' => $permit->plate,
                'apartment' => $permit->apartment,
                'guest' => (int) $permit->guest,
                'signature_version' => $permit->signatureVersion->value,
                'expires_on' => $expiration->format('Y-m-d'),
                'created_at' => $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
