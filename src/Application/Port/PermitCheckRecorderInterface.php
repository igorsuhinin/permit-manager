<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\View\PermitCheckRecord;

interface PermitCheckRecorderInterface
{
    public function record(PermitCheckRecord $check): void;
}
