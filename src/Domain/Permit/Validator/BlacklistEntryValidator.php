<?php

declare(strict_types=1);

namespace App\Domain\Permit\Validator;

use App\Domain\Permit\Exception\InvalidBlacklistEntryException;

final readonly class BlacklistEntryValidator
{
    public function __construct(private PermitIdValidator $ids) {}

    public function validate(string $id, string $reason): void
    {
        if (!$this->ids->isValid($id) || strlen($reason) > 2000) {
            throw new InvalidBlacklistEntryException();
        }
    }
}
