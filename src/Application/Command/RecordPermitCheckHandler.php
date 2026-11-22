<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Port\PermitCheckRecorderInterface;
use App\Application\View\PermitCheckRecord;
use App\Domain\Permit\Service\PermitExpiration;
use Psr\Log\LoggerInterface;

final readonly class RecordPermitCheckHandler
{
    public function __construct(private PermitCheckRecorderInterface $checks, private LoggerInterface $logger) {}

    public function handle(RecordPermitCheckCommand $command): void
    {
        $id = null === $command->id ? null : mb_scrub(string: $command->id, encoding: 'UTF-8');
        try {
            $apartment = null === $command->apartment ? null : mb_scrub($command->apartment, 'UTF-8');
            $expiry = null === $command->term ? null : PermitExpiration::parse(
                $command->term,
                new \DateTimeZone('UTC'),
            );
            $this->checks->record(new PermitCheckRecord(
                permitId: null === $id ? null : mb_strcut(string: $id, start: 0, length: 255, encoding: 'UTF-8'),
                idTruncated: null !== $id && strlen($id) > 255,
                method: substr(string: $command->method, offset: 0, length: 16),
                path: substr(string: $command->path, offset: 0, length: 32),
                outcome: $command->outcome,
                reason: $command->reason,
                status: $command->status,
                checkedAt: $command->checkedAt,
                // MySQL DATE supports years 1000–9999; unusable details must not lose the history row.
                expiresOn: null !== $expiry && $expiry->format('Y') >= 1000 ? $expiry : null,
                apartment: null !== $apartment && '' !== trim($apartment) && strlen($apartment) <= 255
                    ? $apartment : null,
            ));
        } catch (\Throwable $exception) {
            try {
                $this->logger->error('Unable to store permit check history', ['error_type' => $exception::class]);
            } catch (\Throwable) {
                error_log('Unable to store permit check history; logger also failed.');
            }
        }
    }
}
