<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Port\MaintenanceLockInterface;
use App\Application\Port\PermitCheckPrunerInterface;
use App\Application\Validator\PrunePermitChecksValidator;
use App\Application\View\HistoryPruneResult;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class PrunePermitChecksHandler
{
    public function __construct(
        private PermitCheckPrunerInterface $checks,
        private PrunePermitChecksValidator $validator,
        private MaintenanceLockInterface $lock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(PrunePermitChecksCommand $command): HistoryPruneResult
    {
        $this->validator->validate($command);
        $cutoff = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-' . $command->retentionDays . ' days')->format('Y-m-d H:i:s');
        if (!$this->lock->acquire()) {
            return new HistoryPruneResult(cutoff: $cutoff, skipped: true);
        }
        try {
            $limit = $command->batchSize * $command->maxBatches;
            if ($command->dryRun) {
                $count = $this->checks->countBefore(cutoff: $cutoff, limit: $limit + 1);
                $result = new HistoryPruneResult(cutoff: $cutoff, rows: min($limit, $count), hasMore: $count > $limit);
            } else {
                $rows = 0;
                for ($batch = 0; $batch < $command->maxBatches; ++$batch) {
                    $deleted = $this->checks->deleteBefore(cutoff: $cutoff, limit: $command->batchSize);
                    $rows += $deleted;
                    if ($deleted < $command->batchSize) {
                        break;
                    }
                }
                $result = new HistoryPruneResult(
                    cutoff: $cutoff,
                    rows: $rows,
                    hasMore: $this->checks->countBefore(cutoff: $cutoff, limit: 1) > 0,
                );
            }
            $this->logger->info('Permit check history maintenance completed', [
                'dry_run' => $command->dryRun, 'cutoff_utc' => $cutoff, 'rows' => $result->rows,
                'has_more' => $result->hasMore,
            ]);

            return $result;
        } finally {
            $this->lock->release();
        }
    }
}
