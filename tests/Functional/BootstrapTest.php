<?php

declare(strict_types=1);

namespace Tests\Functional;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[Medium]
#[CoversFile(__DIR__ . '/../../public/index.php')]
#[UsesNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class BootstrapTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('failurePoints')]
    public function testStartupFailuresStayPrivate(bool $loggerFails): void
    {
        $directory = sys_get_temp_dir() . '/permit-startup-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config', 'bootstrap'] as $folder) {
            mkdir(directory: $directory . '/' . $folder, permissions: 0700, recursive: true);
        }
        $logs = new TestHandler();
        file_put_contents(filename: $directory . '/vendor/autoload.php', data: '<?php');
        file_put_contents(filename: $directory . '/config/logger.php', data: $loggerFails
            ? '<?php throw new \RuntimeException("private logger configuration");'
            : '<?php return new \Monolog\Logger("test", [$logs]);');
        file_put_contents(
            filename: $directory . '/bootstrap/app.php',
            data: '<?php throw new \RuntimeException("private startup configuration");',
        );
        ob_start();
        $previous = getenv('APP_ROOT');
        putenv('APP_ROOT=' . $directory);
        try {
            require __DIR__ . '/../../public/index.php';
            $body = ob_get_contents();
            self::assertSame('500 Internal Server Error', $body);
            self::assertSame(500, http_response_code());
            if (!$loggerFails) {
                self::assertTrue($logs->hasRecord('Application startup failed', Level::Critical));
                $id = $logs->getRecords()[0]->context['request_id'];
                self::assertIsString($id);
                self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
            }
        } finally {
            putenv(false === $previous ? 'APP_ROOT' : 'APP_ROOT=' . $previous);
            ob_end_clean();
            foreach (['vendor/autoload.php', 'config/logger.php', 'bootstrap/app.php'] as $file) {
                unlink($directory . '/' . $file);
            }
            foreach (['vendor', 'config', 'bootstrap'] as $folder) {
                rmdir($directory . '/' . $folder);
            }
            rmdir($directory);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRelativeApplicationRootFailsWithoutExposingConfiguration(): void
    {
        putenv('APP_ROOT=relative-private-directory');
        ob_start();
        try {
            require __DIR__ . '/../../public/index.php';
            self::assertSame('500 Internal Server Error', ob_get_contents());
            self::assertSame(500, http_response_code());
        } finally {
            ob_end_clean();
        }
    }

    /** @return iterable<array{bool}> */
    public static function failurePoints(): iterable
    {
        yield [false];
        yield [true];
    }
}
