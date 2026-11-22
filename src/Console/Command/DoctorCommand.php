<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Enum\DiagnosticStatus;
use App\Application\Query\DiagnoseDeploymentHandler;
use App\Application\Query\DiagnoseDeploymentQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:doctor', description: 'Inspect deployment readiness without changing application state.')]
final readonly class DoctorCommand
{
    public function __construct(private DiagnoseDeploymentHandler $diagnose) {}

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $checks = $this->diagnose->handle(new DiagnoseDeploymentQuery());
            $rows = [];
            $failed = false;
            foreach ($checks as $check) {
                $rows[] = [$check->status->value, $check->name, $check->message];
                $failed = $failed || DiagnosticStatus::Failed === $check->status;
            }
            $io->table(headers: ['Status', 'Check', 'Result'], rows: $rows);
            $io->note('Read-only inspection using CLI configuration and permissions; no delivery or write probes.');

            return $failed ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable) {
            $io->error('Diagnostics could not finish. Verify private CLI configuration.');

            return Command::FAILURE;
        }
    }
}
