<?php

declare(strict_types=1);

namespace App\Application\Port;

interface MaintenanceLockInterface
{
    public function acquire(): bool;

    public function release(): void;
}
