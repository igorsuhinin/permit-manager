<?php

declare(strict_types=1);

namespace App\Domain\Permit\Port;

interface PermitBlacklistInterface
{
    public function contains(string $id): bool;
}
