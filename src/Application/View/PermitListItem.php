<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Domain\Permit\Enum\PermitStatus;

final readonly class PermitListItem
{
    public function __construct(public PermitRecord $record, public PermitStatus $status) {}
}
