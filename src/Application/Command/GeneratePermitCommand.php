<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Domain\Permit\Model\Permit;

final readonly class GeneratePermitCommand
{
    public function __construct(public Permit $permit, public string $actor) {}
}
