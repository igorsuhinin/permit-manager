<?php

declare(strict_types=1);

namespace App\Domain\Permit\Validator;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Service\PermitExpiration;

final readonly class PermitIssuanceValidator
{
    public function __construct(private PermitIdValidator $ids, private \DateTimeZone $timezone) {}

    public function validate(Permit $permit): \DateTimeImmutable
    {
        if (!$this->ids->isValid($permit->id) || ($permit->guest ? '' === $permit->apartment : '' === $permit->plate)) {
            throw new InvalidPermitInputException(PermitFailureReason::InvalidDetails);
        }
        $expiration = PermitExpiration::parse($permit->term, $this->timezone);
        if (null === $expiration) {
            throw new InvalidPermitInputException(PermitFailureReason::InvalidExpiration);
        }

        return $expiration;
    }
}
