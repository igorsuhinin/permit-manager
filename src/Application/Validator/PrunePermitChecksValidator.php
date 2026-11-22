<?php

declare(strict_types=1);

namespace App\Application\Validator;

use App\Application\Command\PrunePermitChecksCommand;

final readonly class PrunePermitChecksValidator
{
    public function validate(PrunePermitChecksCommand $command): void
    {
        if ($command->retentionDays < 1 || $command->retentionDays > 36500) {
            throw new \InvalidArgumentException('Retention days must be between 1 and 36500.');
        }
        if ($command->batchSize < 1 || $command->batchSize > 1000) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 1000.');
        }
        if ($command->maxBatches < 1 || $command->maxBatches > 100) {
            throw new \InvalidArgumentException('Maximum batches must be between 1 and 100.');
        }
    }
}
