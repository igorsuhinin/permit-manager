<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Authentication\AdminSession;

interface AdminSessionRepositoryInterface
{
    public function find(string $token): ?AdminSession;

    public function save(AdminSession $session, int $now): void;

    public function remove(string $token): void;
}
