<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Enum\PermitCheckOutcome;
use App\Domain\Permit\Enum\PermitFailureReason;

final readonly class RecordPermitCheckCommand
{
    public function __construct(
        public ?string $id,
        public string $method,
        public string $path,
        public PermitCheckOutcome $outcome,
        public ?PermitFailureReason $reason,
        public int $status,
        public \DateTimeImmutable $checkedAt,
        public ?string $term = null,
        public ?string $apartment = null,
    ) {}
}
