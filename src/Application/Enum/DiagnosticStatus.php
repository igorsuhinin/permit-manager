<?php

declare(strict_types=1);

namespace App\Application\Enum;

enum DiagnosticStatus: string
{
    case Passed = 'OK';
    case Failed = 'FAIL';
    case Skipped = 'SKIP';
}
