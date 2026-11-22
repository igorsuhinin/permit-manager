<?php

declare(strict_types=1);

namespace App\Application\View;

final readonly class HistoryPruneResult
{
    public function __construct(
        public string $cutoff,
        public int $rows = 0,
        public bool $hasMore = false,
        public bool $skipped = false,
    ) {}
}
