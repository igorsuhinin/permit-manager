<?php

declare(strict_types=1);

namespace App\Application\Port;

interface BlacklistManagementInterface
{
    public function add(string $id, ?string $reason = null): bool;

    /** Returns true only when an existing entry was removed. */
    public function remove(string $id): bool;
}
