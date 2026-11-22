<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Audit\AdminAudit;
use App\Application\Enum\AdminAuditAction;
use App\Application\Port\TransactionInterface;
use App\Application\Support\PrintablePermitFactory;
use App\Application\View\PrintablePermit;
use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Port\PermitRepositoryInterface;
use App\Domain\Permit\Validator\PermitIssuanceValidator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class GeneratePermitHandler
{
    public function __construct(
        private PermitIssuanceValidator $validator,
        private PermitRepositoryInterface $permits,
        private PrintablePermitFactory $printing,
        private AdminAudit $audit,
        private TransactionInterface $transactions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(GeneratePermitCommand $command): PrintablePermit
    {
        $input = $command->permit;
        $permit = new Permit(
            id: $input->id,
            term: $input->term,
            plate: strtoupper($input->plate),
            guest: $input->guest,
            apartment: $input->apartment,
            signatureVersion: PermitSignatureVersion::HmacSha256,
        );
        $expiration = $this->validator->validate($permit);
        $this->transactions->run(function () use ($permit, $expiration, $command): void {
            if ($this->permits->save($permit, $expiration, $this->clock->now())) {
                $this->audit->record(
                    actor: $command->actor,
                    action: AdminAuditAction::PermitIssued,
                    targetHash: $permit->fingerprint(),
                );
            }
        });
        $print = $this->printing->create($permit);
        $this->logger->info('Permit generated', ['guest' => $permit->guest]);

        return $print;
    }
}
