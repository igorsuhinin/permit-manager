<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Enum\PermitCheckOutcome;

final readonly class ListPermitChecksQuery
{
    public function __construct(public string $search, public ?PermitCheckOutcome $outcome, public int $page) {}
}
