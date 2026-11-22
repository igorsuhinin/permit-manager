<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Domain\Permit\Model\Permit;

final readonly class PermitRecord
{
    public function __construct(
        public string $fingerprint,
        public Permit $permit,
        public \DateTimeImmutable $expiresOn,
        public \DateTimeImmutable $createdAt,
    ) {}
}
