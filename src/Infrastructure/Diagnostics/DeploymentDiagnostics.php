<?php

declare(strict_types=1);

namespace App\Infrastructure\Diagnostics;

use App\Application\Port\DeploymentDiagnosticsQueriesInterface;
use App\Application\View\DiagnosticCheck;

final readonly class DeploymentDiagnostics implements DeploymentDiagnosticsQueriesInterface
{
    public function __construct(
        private DatabaseDiagnostics $database,
        private ConfigurationDiagnostics $configuration,
    ) {}

    /** @return list<DiagnosticCheck> */
    #[\Override]
    public function inspect(): array
    {
        return [...$this->database->inspect(), ...$this->configuration->inspect()];
    }
}
