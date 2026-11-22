<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Application\Enum\AdminAuditAction;

final readonly class AdminAuditEntry
{
    public function __construct(
        public string $actor,
        public AdminAuditAction $action,
        public string $targetHash,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
