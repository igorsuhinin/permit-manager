<?php

declare(strict_types=1);

namespace App\Domain\Permit\Validator;

final class PermitIdValidator
{
    public function isValid(string $id): bool
    {
        return '' !== trim($id)
            && strlen($id) <= 255
            && 0 === preg_match(pattern: '/[\x00-\x1f\x7f]/', subject: $id);
    }
}
