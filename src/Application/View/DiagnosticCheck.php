<?php

declare(strict_types=1);

namespace App\Application\View;

use App\Application\Enum\DiagnosticStatus;

final readonly class DiagnosticCheck
{
    public function __construct(public string $name, public DiagnosticStatus $status, public string $message) {}
}
