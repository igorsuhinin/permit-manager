<?php

declare(strict_types=1);

namespace App\Domain\Permit\Exception;

use App\Domain\Permit\Enum\PermitFailureReason;

final class InvalidPermitInputException extends \InvalidArgumentException
{
    public function __construct(public readonly PermitFailureReason $reason)
    {
        parent::__construct($reason->value);
    }
}
