<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Application\Enum\PermitCheckOutcome;
use App\Domain\Permit\Enum\PermitFailureReason;

final readonly class PermitCheckRecord
{
    public function __construct(
        public ?string $permitId,
        public bool $idTruncated,
        public string $method,
        public string $path,
        public PermitCheckOutcome $outcome,
        public ?PermitFailureReason $reason,
        public int $status,
        public \DateTimeImmutable $checkedAt,
        public ?string $id = null,
        public ?\DateTimeImmutable $expiresOn = null,
        public ?string $apartment = null,
    ) {}
}
