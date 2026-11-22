<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Logging\LoggerFactory;
use Monolog\Handler\RotatingFileHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(LoggerFactory::class)]
final class LoggerFactoryTest extends TestCase
{
    public function testRotatingJsonLogsContainDiagnosticsWithoutTraceArguments(): void
    {
        $directory = sys_get_temp_dir() . '/permit-logs-' . bin2hex(random_bytes(8));
        $previous = ini_set(option: 'zend.exception_ignore_args', value: '0');
        $exception = (static fn(string $credential): \Throwable => new \RuntimeException('private diagnostic'))(
            'secret-argument',
        );
        ini_set(option: 'zend.exception_ignore_args', value: (string) $previous);
        $logger = new LoggerFactory()->create($directory . '/app.log');
        try {
            $logger->error('Internal failure', ['exception' => $exception]);
            $logger->close();
            $handler = $logger->getHandlers()[0] ?? self::fail('Expected a log handler');
            self::assertInstanceOf(RotatingFileHandler::class, $handler);
            $path = $handler->getUrl();
            self::assertNotNull($path);
            $content = file_get_contents($path);
            self::assertNotFalse($content);
            self::assertJson($content);
            self::assertStringContainsString('private diagnostic', $content);
            self::assertStringContainsString('"trace":', $content);
            self::assertStringNotContainsString('secret-argument', $content);
        } finally {
            $logger->close();
            $files = glob($directory . '/*');
            self::assertIsArray($files);
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
