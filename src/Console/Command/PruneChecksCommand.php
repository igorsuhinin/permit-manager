<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Command\PrunePermitChecksCommand;
use App\Application\Command\PrunePermitChecksHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'checks:prune', description: 'Preview or delete old permit check history in bounded batches.')]
final readonly class PruneChecksCommand
{
    /** @param array{retention_days: int, batch_size: int, max_batches: int, lock_file: string} $defaults */
    public function __construct(
        private PrunePermitChecksHandler $prune,
        private array $defaults,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Keep this many days of history (default: config/maintenance.php).')]
        ?int $days = null,
        #[Option(description: 'Rows per batch, 1–1000 (default: config/maintenance.php).')]
        ?int $batchSize = null,
        #[Option(description: 'Maximum batches, 1–100 (default: config/maintenance.php).')]
        ?int $maxBatches = null,
        #[Option(description: 'Delete eligible history; otherwise only preview.')]
        bool $apply = false,
        #[Option(description: 'Explicitly preview eligible history without deleting it.')]
        bool $dryRun = false,
    ): int {
        try {
            if ($apply && $dryRun) {
                throw new \InvalidArgumentException('Use either --apply or --dry-run.');
            }
            $result = $this->prune->handle(new PrunePermitChecksCommand(
                retentionDays: $days ?? $this->defaults['retention_days'],
                batchSize: $batchSize ?? $this->defaults['batch_size'],
                maxBatches: $maxBatches ?? $this->defaults['max_batches'],
                dryRun: !$apply,
            ));
            if ($result->skipped) {
                $io->note('Skipped: another history maintenance process holds the lock.');

                return Command::SUCCESS;
            }
            $io->text([
                'Mode: ' . ($apply ? 'apply' : 'dry-run'),
                'Cutoff (UTC, exclusive): ' . $result->cutoff,
                ($apply ? 'Deleted: ' : 'Would delete: ') . $result->rows,
                'More eligible rows: ' . ($result->hasMore ? 'yes; run again to continue' : 'no'),
            ]);

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        } catch (\Throwable $exception) {
            try {
                $this->logger->error('Permit check history maintenance failed', ['error_type' => $exception::class]);
            } catch (\Throwable) {
                error_log('Permit check history maintenance failed; logger also failed.');
            }
            $io->error('History maintenance failed. Check the private application logs.');

            return Command::FAILURE;
        }
    }
}
