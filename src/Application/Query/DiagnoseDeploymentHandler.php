<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Port\DeploymentDiagnosticsQueriesInterface;
use App\Application\View\DiagnosticCheck;

final readonly class DiagnoseDeploymentHandler
{
    public function __construct(private DeploymentDiagnosticsQueriesInterface $diagnostics) {}

    /** @return list<DiagnosticCheck> */
    public function handle(DiagnoseDeploymentQuery $query): array
    {
        return $this->diagnostics->inspect();
    }
}
