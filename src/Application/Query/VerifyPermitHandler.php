<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Service\PermitVerifier;
use Psr\Clock\ClockInterface;

final readonly class VerifyPermitHandler
{
    public function __construct(private PermitVerifier $verifier, private ClockInterface $clock) {}

    public function handle(VerifyPermitQuery $query): ?PermitFailureReason
    {
        return $this->verifier->verify($query->permit, $query->signature, $this->clock->now());
    }
}
