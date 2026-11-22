<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Enum\PermitCheckOutcome;
use App\Application\Port\PermitCheckQueriesInterface;
use App\Application\Port\PermitCheckRecorderInterface;
use App\Application\View\PermitCheckRecord;
use App\Domain\Permit\Enum\PermitFailureReason;
use Doctrine\DBAL\Connection;

final readonly class PermitCheckRepository implements PermitCheckRecorderInterface, PermitCheckQueriesInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function record(PermitCheckRecord $check): void
    {
        $this->connection->insert('permit_checks', [
            'permit_id' => $check->permitId,
            'id_truncated' => (int) $check->idTruncated,
            'method' => $check->method,
            'path' => $check->path,
            'result' => $check->outcome->value,
            'reason' => $check->reason?->value,
            'status' => $check->status,
            'checked_at' => $check->checkedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'expires_on' => $check->expiresOn?->format('Y-m-d'),
            'apartment' => $check->apartment,
        ]);
    }

    /** @return list<PermitCheckRecord> */
    #[\Override]
    public function search(string $id, ?PermitCheckOutcome $result, int $offset, int $limit): array
    {
        $conditions = [];
        $parameters = [];
        if ('' !== $id) {
            $conditions[] = "permit_id LIKE ? ESCAPE '!'";
            $parameters[] = '%' . str_replace(search: ['!', '%', '_'], replace: ['!!', '!%', '!_'], subject: $id) . '%';
        }
        if (null !== $result) {
            $conditions[] = 'result = ?';
            $parameters[] = $result->value;
        }
        $sql = 'SELECT * FROM permit_checks' . ([] === $conditions ? '' : ' WHERE ' . implode(
            ' AND ',
            $conditions,
        )) . ' ORDER BY id DESC';
        /** @var list<array{
         *     id: int|string,
         *     permit_id: ?string,
         *     id_truncated: bool|int|string,
         *     method: string,
         *     path: string,
         *     result: string,
         *     reason: ?string,
         *     status: int|string,
         *     checked_at: string,
         *     expires_on: ?string,
         *     apartment: ?string,
         * }> $rows
         */
        $rows = $this->connection->fetchAllAssociative(
            $this->connection->getDatabasePlatform()->modifyLimitQuery(query: $sql, limit: $limit, offset: $offset),
            $parameters,
        );

        return array_map(static fn(array $row): PermitCheckRecord => new PermitCheckRecord(
            permitId: $row['permit_id'],
            idTruncated: (bool) $row['id_truncated'],
            method: $row['method'],
            path: $row['path'],
            outcome: PermitCheckOutcome::from($row['result']),
            reason: null === $row['reason'] ? null : PermitFailureReason::from($row['reason']),
            status: (int) $row['status'],
            checkedAt: new \DateTimeImmutable($row['checked_at'], new \DateTimeZone('UTC')),
            id: (string) $row['id'],
            expiresOn: null === $row['expires_on'] ? null : new \DateTimeImmutable($row['expires_on']),
            apartment: $row['apartment'],
        ), $rows);
    }
}
