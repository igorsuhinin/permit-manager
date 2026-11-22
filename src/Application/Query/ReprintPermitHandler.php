<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Exception\PermitNotFoundException;
use App\Application\Port\PermitLookupInterface;
use App\Application\Support\PrintablePermitFactory;
use App\Application\View\PrintablePermit;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Domain\Permit\Service\PermitSigner;
use App\Domain\Permit\Service\PermitVerifier;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class ReprintPermitHandler
{
    public function __construct(
        private PermitLookupInterface $permits,
        private PermitVerifier $verifier,
        private PermitSigner $signer,
        private PrintablePermitFactory $printing,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(ReprintPermitQuery $query): PrintablePermit
    {
        $permit = $this->permits->find($query->fingerprint);
        if (null === $permit) {
            throw new PermitNotFoundException();
        }
        $reason = $this->verifier->verify($permit, $this->signer->sign($permit), $this->clock->now());
        if (null !== $reason) {
            throw new InvalidPermitInputException($reason);
        }
        $print = $this->printing->create($permit);
        $this->logger->info('Permit reprinted', ['guest' => $permit->guest]);

        return $print;
    }
}
