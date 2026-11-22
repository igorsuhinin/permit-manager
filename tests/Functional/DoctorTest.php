<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Enum\DiagnosticStatus;
use App\Console\Command\DoctorCommand;
use App\Infrastructure\Diagnostics\ConfigurationDiagnostics;
use Database\Migrations\Version20261105000000;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\Database;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class DoctorTest extends TestCase
{
    private Connection $connection;
    private string $directory;
    /** @var array<string, string|false> */
    private array $environment = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/permit-doctor-' . bin2hex(random_bytes(8));
        mkdir(directory: $this->directory . '/templates', permissions: 0700, recursive: true);
        file_put_contents(filename: $this->directory . '/ui.php', data: '<?php return [];');
        foreach (
            [
                'PUBLIC_UI_CONFIG' => $this->directory . '/ui.php',
                'PUBLIC_TEMPLATE_DIRECTORY' => $this->directory . '/templates',
                'CHECK_HISTORY_LOCK_FILE' => $this->directory . '/new-maintenance/history.lock',
            ] as $name => $value
        ) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->connection = Database::create();
        $this->connection->executeStatement(
            'CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY)',
        );
        $this->connection->insert(
            'doctrine_migration_versions',
            ['version' => Version20261105000000::class],
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name . '=' . $value);
        }
        $files = new \RecursiveIteratorIterator(
            iterator: new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            mode: \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo|string $file */
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                chmod(filename: $file->getPathname(), permissions: 0700);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
        $this->connection->close();
    }

    /** @param array<string, mixed> $definitions */
    private function tester(array $definitions = []): CommandTester
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/../../config/container.php');
        $builder->addDefinitions($definitions + [Connection::class => $this->connection]);
        /** @var DoctorCommand $command */
        $command = $builder->build()->get(DoctorCommand::class);

        return new CommandTester($command);
    }

    public function testHealthyChecksPreserveAllRowsAndDoNotCreateWritableTargets(): void
    {
        $before = $this->snapshot();
        $logger = new Logger(name: 'synthetic', handlers: [
            new RotatingFileHandler($this->directory . '/new-logs/application.log'),
        ]);
        $tester = $this->tester([LoggerInterface::class => $logger]);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(9, substr_count(haystack: $tester->getDisplay(), needle: ' OK '));
        self::assertSame($before, $this->snapshot());
        self::assertDirectoryDoesNotExist($this->directory . '/new-logs');
        self::assertDirectoryDoesNotExist($this->directory . '/new-maintenance');
        self::assertStringNotContainsString($this->directory, $tester->getDisplay());
        self::assertStringNotContainsString('test-secret', $tester->getDisplay());
        self::assertStringNotContainsString(str_repeat('68', 32), $tester->getDisplay());
        self::assertStringNotContainsString('DEMO-BLOCKED', $tester->getDisplay());
    }

    public function testUnavailableDatabaseSkipsDependentChecksAndStillChecksConfiguration(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with('SELECT 1')->willThrowException(new \RuntimeException('private database credentials'));
        $tester = $this->tester([Connection::class => $connection]);
        self::assertSame(1, $tester->execute([]));
        self::assertSame(3, substr_count(haystack: $tester->getDisplay(), needle: ' SKIP '));
        self::assertSame(5, substr_count(haystack: $tester->getDisplay(), needle: ' OK '));
        self::assertStringNotContainsString('private database credentials', $tester->getDisplay());
    }

    #[DataProvider('brokenDatabase')]
    public function testDatabaseProblemsAreReportedWithoutChangingRows(string $sql, string $check): void
    {
        $this->connection->executeStatement($sql);
        $before = $this->snapshot();
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\s*FAIL\s+' . $check . '\s+/m', $tester->getDisplay());
        self::assertSame($before, $this->snapshot());
        self::assertStringNotContainsString('private-value', $tester->getDisplay());
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenDatabase(): iterable
    {
        yield 'table' => ['DROP TABLE admin_audit', 'Schema'];
        yield 'column' => ['ALTER TABLE permits DROP COLUMN signature_version', 'Schema'];
        yield 'index' => ['DROP INDEX permit_checks_checked_at', 'Schema'];
        yield 'uninitialized metadata' => ['DROP TABLE doctrine_migration_versions', 'Migrations'];
        yield 'pending version' => ['DELETE FROM doctrine_migration_versions', 'Migrations'];
        yield 'unavailable version' => [
            "INSERT INTO doctrine_migration_versions VALUES ('Database\\Migrations\\Version20000101000000')",
            'Migrations',
        ];
        yield 'missing key' => ["DELETE FROM app_settings WHERE setting_key = 'hmac_signing_key'", 'Settings'];
        yield 'invalid key' => [
            "UPDATE app_settings SET setting_value = 'private-value' WHERE setting_key = 'hmac_signing_key'",
            'Settings',
        ];
        yield 'invalid timezone' => [
            "UPDATE app_settings SET setting_value = 'private-value' WHERE setting_key = 'timezone'", 'Settings',
        ];
        yield 'missing username' => ["DELETE FROM app_settings WHERE setting_key = 'admin_username'", 'Settings'];
    }

    #[DataProvider('brokenOverrides')]
    public function testExplicitMissingOverridesDoNotSilentlyUseDefaults(string $variable, string $check): void
    {
        putenv($variable . '=' . $this->directory . '/private-missing');
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\s*FAIL\s+' . $check . '\s+/m', $tester->getDisplay());
        self::assertStringNotContainsString('private-missing', $tester->getDisplay());
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenOverrides(): iterable
    {
        yield 'wording' => ['PUBLIC_UI_CONFIG', 'Public wording'];
        yield 'templates' => ['PUBLIC_TEMPLATE_DIRECTORY', 'Templates'];
    }

    #[DataProvider('malformedWording')]
    public function testMalformedPrivateConfigurationCannotLeakOutput(string $code): void
    {
        file_put_contents(filename: $this->directory . '/ui.php', data: $code);
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertStringNotContainsString('private-value', $tester->getDisplay());
        self::assertStringContainsString('Selected public wording configuration', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedWording(): iterable
    {
        yield 'shape' => ["<?php return ['private-value'];"];
        yield 'syntax' => ['<?php private-value broken syntax'];
        yield 'output' => ["<?php echo 'private-value'; return [];"];
        yield 'exception' => ["<?php throw new RuntimeException('private-value');"];
    }

    public function testMalformedSelectedTwigIsDetectedWithoutWritingCache(): void
    {
        file_put_contents(
            filename: $this->directory . '/templates/valid.html.twig',
            data: '{% private-value invalid syntax %}',
        );
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertStringNotContainsString('private-value', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Templates\s+/m', $tester->getDisplay());
    }

    public function testPermissionFailuresAreDetectedWithoutRepairingPermissions(): void
    {
        $blocked = $this->directory . '/blocked';
        file_put_contents(filename: $blocked, data: 'unchanged');
        chmod(filename: $blocked, permissions: 0400);
        putenv('CHECK_HISTORY_LOCK_FILE=' . $blocked);
        $logger = new Logger(name: 'synthetic', handlers: [new RotatingFileHandler($blocked . '/app.log')]);
        $tester = $this->tester([LoggerInterface::class => $logger]);
        self::assertSame(1, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Maintenance lock\s+/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Logger\s+/m', $tester->getDisplay());
        self::assertSame('unchanged', file_get_contents($blocked));
        self::assertSame(0400, (int) fileperms($blocked) & 0777);
    }

    public function testEnabledCacheIsInspectedWithoutCreatingItsDirectory(): void
    {
        $cache = $this->directory . '/new-cache';
        $templates = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'), ['cache' => $cache]);
        $tester = $this->tester([Environment::class => $templates]);
        $tester->execute([]);
        self::assertMatchesRegularExpression('/^\s*OK\s+Twig cache\s+/m', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($cache);
    }

    public function testBrokenLoggerConfigurationDoesNotPreventOtherChecks(): void
    {
        $tester = $this->tester([
            LoggerInterface::class => static function (): LoggerInterface {
                throw new \RuntimeException('private-value');
            },
        ]);
        self::assertSame(1, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Logger\s+/m', $tester->getDisplay());
        self::assertSame(8, substr_count(haystack: $tester->getDisplay(), needle: ' OK '));
        self::assertStringNotContainsString('private-value', $tester->getDisplay());
    }

    public function testMissingOptionalOverridesAndRelativeTargetsAreSupported(): void
    {
        putenv('PUBLIC_UI_CONFIG');
        putenv('PUBLIC_TEMPLATE_DIRECTORY');
        $configuration = new ConfigurationDiagnostics(
            rootDirectory: $this->directory,
            templates: fn(): Environment => new Environment(new FilesystemLoader($this->directory . '/templates')),
            publicUi: static fn(): array => [],
            maintenance: static fn(): array => ['lock_file' => 'relative/history.lock'],
            logger: static fn(): Logger => new Logger(name: 'synthetic', handlers: [new ErrorLogHandler()]),
        );
        foreach ($configuration->inspect() as $check) {
            self::assertSame(DiagnosticStatus::Passed, $check->status, $check->name);
        }
        self::assertDirectoryDoesNotExist($this->directory . '/relative');
    }

    public function testUnwritableParentAndUnreadableOverrideAreNotRepaired(): void
    {
        mkdir(directory: $this->directory . '/blocked', permissions: 0500);
        putenv('CHECK_HISTORY_LOCK_FILE=' . $this->directory . '/blocked/missing/history.lock');
        chmod(filename: $this->directory . '/ui.php', permissions: 0000);
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Public wording\s+/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^\s*FAIL\s+Maintenance lock\s+/m', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->directory . '/blocked/missing');
        self::assertSame(0500, (int) fileperms($this->directory . '/blocked') & 0777);
    }

    public function testExistingLockFileIsInspectedWithoutAcquiringOrWritingIt(): void
    {
        $path = $this->directory . '/history.lock';
        file_put_contents(filename: $path, data: 'unchanged');
        putenv('CHECK_HISTORY_LOCK_FILE=' . $path);
        $handle = fopen(filename: $path, mode: 'r+');
        self::assertIsResource($handle);
        self::assertTrue(flock(operation: LOCK_EX | LOCK_NB, stream: $handle));
        try {
            $tester = $this->tester();
            self::assertSame(0, $tester->execute([]));
            self::assertSame('unchanged', file_get_contents($path));
        } finally {
            fclose($handle);
        }
    }

    #[DataProvider('unavailableSentry')]
    public function testInvalidOrMissingTelemetryHandlerIsReportedPrivately(string $dsn): void
    {
        $previous = getenv('SENTRY_DSN');
        putenv('SENTRY_DSN=' . $dsn);
        try {
            $tester = $this->tester([LoggerInterface::class => new Logger(name: 'synthetic')]);
            self::assertSame(1, $tester->execute([]));
            self::assertMatchesRegularExpression('/^\s*FAIL\s+Logger\s+/m', $tester->getDisplay());
            self::assertStringNotContainsString($dsn, $tester->getDisplay());
        } finally {
            putenv(false === $previous ? 'SENTRY_DSN' : 'SENTRY_DSN=' . $previous);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unavailableSentry(): iterable
    {
        yield 'invalid DSN' => ['private-invalid-dsn'];
        yield 'missing configured handler' => ['https://public@example.invalid/1'];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $rows = [];
        foreach ($this->connection->createSchemaManager()->introspectTableNames() as $name) {
            $table = $name->getUnqualifiedName()->getValue();
            $rows[$table] = $this->connection->fetchAllAssociative(
                'SELECT * FROM ' . $name->toSQL($this->connection->getDatabasePlatform()),
            );
        }

        return $rows;
    }
}
