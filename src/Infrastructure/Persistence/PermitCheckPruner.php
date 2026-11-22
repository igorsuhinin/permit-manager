<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\PermitCheckPrunerInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PermitCheckPruner implements PermitCheckPrunerInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function countBefore(string $cutoff, int $limit): int
    {
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(
            query: 'SELECT 1 FROM permit_checks WHERE checked_at < ?',
            limit: $limit,
        );

        /** @var int|string $count */
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM (' . $sql . ') AS expired', [$cutoff]);

        return (int) $count;
    }

    #[\Override]
    public function deleteBefore(string $cutoff, int $limit): int
    {
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(
            query: 'SELECT id FROM permit_checks WHERE checked_at < ? ORDER BY checked_at, id',
            limit: $limit,
        );
        $ids = $this->connection->fetchFirstColumn($sql, [$cutoff]);
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            sql: 'DELETE FROM permit_checks WHERE id IN (?) AND checked_at < ?',
            params: [$ids, $cutoff],
            types: [ArrayParameterType::STRING, ParameterType::STRING],
        );
    }
}
