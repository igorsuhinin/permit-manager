<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\BlacklistManagementInterface;
use App\Application\Port\BlacklistQueriesInterface;
use App\Application\View\BlacklistItem;
use App\Domain\Permit\Port\PermitBlacklistInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class BlacklistRepository implements
    PermitBlacklistInterface,
    BlacklistManagementInterface,
    BlacklistQueriesInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function contains(string $id): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM blacklisted_ids WHERE id_hash = ?',
            [hash(algo: 'sha256', data: $id)],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    #[\Override]
    public function matchingIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $hashes = array_map(static fn(string $id): string => hash(algo: 'sha256', data: $id), $ids);
        /** @var list<string> $matches */
        $matches = $this->connection->executeQuery(
            sql: 'SELECT permit_id FROM blacklisted_ids WHERE id_hash IN (?)',
            params: [$hashes],
            types: [ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return $matches;
    }

    /** @return list<BlacklistItem> */
    #[\Override]
    public function search(string $search, int $offset, int $limit): array
    {
        $sql = 'SELECT permit_id, reason FROM blacklisted_ids';
        $parameters = [];
        if ('' !== $search) {
            $sql .= " WHERE permit_id LIKE ? ESCAPE '!'";
            $parameters[] = '%' . str_replace(
                search: ['!', '%', '_'],
                replace: ['!!', '!%', '!_'],
                subject: $search,
            ) . '%';
        }
        $sql .= ' ORDER BY permit_id, id_hash';
        /** @var list<array{permit_id: string, reason: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $this->connection->getDatabasePlatform()->modifyLimitQuery(query: $sql, limit: $limit, offset: $offset),
            $parameters,
        );

        return array_map(
            static fn(array $row): BlacklistItem => new BlacklistItem(id: $row['permit_id'], reason: $row['reason']),
            $rows,
        );
    }

    #[\Override]
    public function add(string $id, ?string $reason = null): bool
    {
        try {
            $this->connection->insert(
                'blacklisted_ids',
                ['id_hash' => hash(algo: 'sha256', data: $id), 'permit_id' => $id, 'reason' => $reason],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    #[\Override]
    public function remove(string $id): bool
    {
        return $this->connection->delete('blacklisted_ids', ['id_hash' => hash(algo: 'sha256', data: $id)]) > 0;
    }
}
