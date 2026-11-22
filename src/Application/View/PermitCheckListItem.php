<?php

declare(strict_types=1);

namespace App\Application\View;

final readonly class PermitCheckListItem
{
    public function __construct(
        public PermitCheckRecord $record,
        public bool $blacklisted,
        public bool $canBlacklist,
    ) {}
}
