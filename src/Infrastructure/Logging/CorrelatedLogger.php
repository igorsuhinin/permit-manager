<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Port\LogContextInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class CorrelatedLogger extends AbstractLogger
{
    public function __construct(
        private readonly LogContextInterface $context,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array-key, mixed> $context
     *
     * @psalm-capabilities read-props|write-props|read-globals|write-globals|io|time
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $id = $this->context->requestId();
        $context = null === $id ? $context : array_replace($context, ['request_id' => $id]);
        try {
            $this->logger->log(level: $level, message: $message, context: $context);
        } catch (\Throwable $exception) {
            error_log('Application logger failed; ' . (null === $id ? '' : 'request_id=' . $id . '; ')
                . 'error_type=' . $exception::class);

            throw $exception;
        }
    }
}
