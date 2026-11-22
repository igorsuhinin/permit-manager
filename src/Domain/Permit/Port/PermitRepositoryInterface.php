<?php

declare(strict_types=1);

namespace App\Domain\Permit\Port;

use App\Domain\Permit\Model\Permit;

interface PermitRepositoryInterface
{
    /** Returns true only when a new issuance record was inserted. */
    public function save(Permit $permit, \DateTimeImmutable $expiration, \DateTimeImmutable $createdAt): bool;
}
