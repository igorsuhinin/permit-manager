<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\AdminAuditTrailInterface;
use App\Application\View\AdminAuditEntry;
use Doctrine\DBAL\Connection;

final readonly class AdminAuditTrail implements AdminAuditTrailInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function append(AdminAuditEntry $entry): void
    {
        $this->connection->insert('admin_audit', [
            'actor' => $entry->actor,
            'action' => $entry->action->value,
            'target_hash' => $entry->targetHash,
            'occurred_at' => $entry->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }
}
