<?php

declare(strict_types=1);

namespace App\Application\Port;

interface PermitCheckPrunerInterface
{
    /** Count at most $limit rows strictly older than the UTC cutoff. */
    public function countBefore(string $cutoff, int $limit): int;

    /** Delete at most $limit oldest rows strictly older than the UTC cutoff. */
    public function deleteBefore(string $cutoff, int $limit): int;
}
