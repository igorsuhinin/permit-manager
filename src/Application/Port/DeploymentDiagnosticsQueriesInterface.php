<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\View\DiagnosticCheck;

interface DeploymentDiagnosticsQueriesInterface
{
    /** @return list<DiagnosticCheck> */
    public function inspect(): array;
}
