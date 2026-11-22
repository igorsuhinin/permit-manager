<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Audit\AdminAudit;
use App\Application\Enum\AdminAuditAction;
use App\Application\Port\BlacklistManagementInterface;
use App\Application\Port\TransactionInterface;
use App\Domain\Permit\Model\BlacklistEntry;
use App\Domain\Permit\Validator\BlacklistEntryValidator;
use Psr\Log\LoggerInterface;

final readonly class RemoveBlacklistedIdHandler
{
    public function __construct(
        private BlacklistEntryValidator $validator,
        private BlacklistManagementInterface $blacklist,
        private AdminAudit $audit,
        private TransactionInterface $transactions,
        private LoggerInterface $logger,
    ) {}

    public function handle(RemoveBlacklistedIdCommand $command): void
    {
        $this->validator->validate(id: $command->id, reason: $command->reason);
        $entry = new BlacklistEntry(id: $command->id, reason: $command->reason);
        $this->transactions->run(function () use ($entry, $command): void {
            if ($this->blacklist->remove($entry->id)) {
                $this->audit->record(
                    actor: $command->actor,
                    action: AdminAuditAction::BlacklistRemoved,
                    targetHash: hash(algo: 'sha256', data: $entry->id),
                );
            }
        });
        $this->logger->info(
            'Admin blacklist changed',
            ['action' => 'remove', 'id_hash' => hash(algo: 'sha256', data: $entry->id)],
        );
    }
}
