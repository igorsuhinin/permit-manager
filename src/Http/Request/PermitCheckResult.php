<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Enum\PermitCheckOutcome;
use App\Domain\Permit\Enum\PermitFailureReason;

final class PermitCheckResult
{
    public PermitCheckOutcome $outcome = PermitCheckOutcome::Error;
    public ?PermitFailureReason $reason = PermitFailureReason::InternalError;

    public function complete(PermitCheckOutcome $outcome, ?PermitFailureReason $reason): void
    {
        $this->outcome = $outcome;
        $this->reason = $reason;
    }
}
