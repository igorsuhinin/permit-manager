<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Logging\CorrelatedLogger;
use App\Infrastructure\Logging\RequestLogContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Small]
#[CoversClass(CorrelatedLogger::class)]
#[UsesClass(RequestLogContext::class)]
final class CorrelatedLoggerTest extends TestCase
{
    public function testActiveIdOverridesCallerContextWhileOtherFieldsAndLevelArePreserved(): void
    {
        $context = new RequestLogContext();
        $id = $context->begin();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')
            ->with('warning', 'Synthetic warning', ['request_id' => $id, 'status' => 403]);
        new CorrelatedLogger($context, $logger)->warning(
            'Synthetic warning',
            ['request_id' => 'spoofed', 'status' => 403],
        );
        $context->end();
    }

    public function testCallsOutsideRequestKeepOriginalContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with('info', 'CLI operation', ['rows' => 2]);
        new CorrelatedLogger(new RequestLogContext(), $logger)->info('CLI operation', ['rows' => 2]);
    }

    public function testSinkFailureEmitsOnlySafeCorrelationMetadataAndStillThrows(): void
    {
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'correlation-log-');
        self::assertNotFalse($path);
        $previous = ini_set(option: 'error_log', value: $path);
        $context = new RequestLogContext();
        $id = $context->begin();
        $failure = new \RuntimeException('private sink configuration');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->willThrowException($failure);
        try {
            new CorrelatedLogger($context, $logger)->error('Synthetic error');
            self::fail('The original failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString('request_id=' . $id, $contents);
            self::assertStringContainsString('error_type=RuntimeException', $contents);
            self::assertStringNotContainsString('private sink configuration', $contents);
        } finally {
            $context->end();
            ini_set(option: 'error_log', value: (string) $previous);
            unlink($path);
        }
    }
}
