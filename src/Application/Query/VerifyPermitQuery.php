<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Permit\Model\Permit;

final readonly class VerifyPermitQuery
{
    public function __construct(public Permit $permit, public string $signature) {}
}
