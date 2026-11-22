<?php

declare(strict_types=1);

namespace App\Domain\Permit\Service;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Port\PermitBlacklistInterface;

final readonly class PermitVerifier
{
    public function __construct(
        private PermitSigner $signer,
        private PermitBlacklistInterface $blacklist,
        private \DateTimeZone $timezone,
    ) {}

    public function verify(
        Permit $permit,
        string $signature,
        \DateTimeImmutable $now,
    ): ?PermitFailureReason {
        if ($this->blacklist->contains($permit->id)) {
            return PermitFailureReason::Revoked;
        }

        $reason = $this->signer->matches($permit, $signature)
            ? null : PermitFailureReason::InvalidSignature;
        $expiration = PermitExpiration::parse($permit->term, $this->timezone);
        if (null === $expiration) {
            $reason = PermitFailureReason::InvalidExpiration;
        } elseif ($expiration < $now) {
            $reason = PermitFailureReason::Expired;
        }

        return $reason;
    }
}
