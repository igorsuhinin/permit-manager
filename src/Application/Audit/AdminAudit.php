<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Enum\AdminAuditAction;
use App\Application\Port\AdminAuditTrailInterface;
use App\Application\View\AdminAuditEntry;
use Psr\Clock\ClockInterface;

final readonly class AdminAudit
{
    public function __construct(private AdminAuditTrailInterface $entries, private ClockInterface $clock) {}

    public function record(string $actor, AdminAuditAction $action, string $targetHash): void
    {
        $this->entries->append(new AdminAuditEntry(
            actor: $actor,
            action: $action,
            targetHash: $targetHash,
            occurredAt: $this->clock->now(),
        ));
    }
}
