<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class PrunePermitChecksCommand
{
    public function __construct(
        public int $retentionDays,
        public int $batchSize,
        public int $maxBatches,
        public bool $dryRun = true,
    ) {}
}
