<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Command\PrepareInitialSettingsHandler;
use App\Application\Exception\InitialSettingsAlreadyExistException;
use App\Application\Port\InitialSettingsStoreInterface;
use App\Application\Validator\InitialSettingsValidator;
use App\Console\Command\SetupCommand;
use App\Infrastructure\Configuration\InitialSettingsFile;
use App\Infrastructure\Persistence\SettingsRepository;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\Database;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class SetupTest extends TestCase
{
    private string $directory;
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/permit-setup-' . bin2hex(random_bytes(8));
        mkdir(directory: $this->directory, permissions: 0700);
        $this->path = $this->directory . '/initial-settings.php';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        foreach (false === $files ? [] : $files as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            } else {
                $children = glob($file . '/*');
                foreach (false === $children ? [] : $children as $child) {
                    unlink($child);
                }
                rmdir($file);
            }
        }
        rmdir($this->directory);
    }

    private function tester(?InitialSettingsStoreInterface $store = null): CommandTester
    {
        return new CommandTester(new SetupCommand(new PrepareInitialSettingsHandler(
            $store ?? new InitialSettingsFile($this->path),
            new InitialSettingsValidator(),
        )));
    }

    public function testRealCliWorksWithoutDatabaseCredentials(): void
    {
        $process = proc_open(
            command: [PHP_BINARY, __DIR__ . '/../../bin/console.php', 'app:setup', '--no-interaction',
                '--admin-username=cli-admin', '--base-url=https://example.com', '--timezone=UTC'],
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
            env_vars: array_merge(getenv(), [
                'INITIAL_SETTINGS_CONFIG' => $this->path, 'DB_HOST' => 'unavailable.invalid', 'SENTRY_DSN' => '',
            ]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('', $error);
        self::assertIsString($output);
        self::assertStringContainsString('Admin username: cli-admin', $output);
        $settings = new InitialSettingsFile($this->path)->load();
        self::assertSame('cli-admin', $settings->adminUsername);
        self::assertSame('UTC', $settings->timezone);
    }

    public function testInitialConfigurationPathsResolveFromTheApplicationRoot(): void
    {
        $previous = getenv('INITIAL_SETTINGS_CONFIG');
        try {
            foreach (['private/initial.php', $this->path, ''] as $path) {
                putenv('INITIAL_SETTINGS_CONFIG=' . $path);
                $resolved = require __DIR__ . '/../../config/initial-settings-path.php';
                $expected = '' === $path ? 'protected/initial-settings.php' : $path;
                self::assertSame(
                    str_starts_with(haystack: $expected, needle: '/')
                        ? $expected : dirname(__DIR__, 2) . '/' . $expected,
                    $resolved,
                );
            }
        } finally {
            putenv(false === $previous ? 'INITIAL_SETTINGS_CONFIG' : 'INITIAL_SETTINGS_CONFIG=' . $previous);
        }
    }

    #[DataProvider('defaultFlows')]
    public function testDefaultsCreatePrivateConfigurationAndDisplayedPasswordCanAuthenticate(bool $interactive): void
    {
        $tester = $this->tester();
        if ($interactive) {
            $tester->setInputs(['', '', '', '', '', '']);
        }
        self::assertSame(0, $tester->execute([], ['interactive' => $interactive]));
        self::assertSame(1, preg_match(
            pattern: '/Generated password: ([a-f0-9]{32})/',
            subject: $tester->getDisplay(),
            matches: $password,
        ));
        $settings = new InitialSettingsFile($this->path)->load();
        self::assertSame('admin', $settings->adminUsername);
        self::assertSame(0600, (int) fileperms($this->path) & 0777);
        self::assertTrue(password_verify(password: $password[1], hash: $settings->passwordHash));
        self::assertStringNotContainsString($settings->signingSecret, $tester->getDisplay());
        self::assertStringNotContainsString($settings->hmacSigningKey, $tester->getDisplay());
        self::assertStringNotContainsString($password[1], (string) file_get_contents($this->path));
        $previous = getenv('INITIAL_SETTINGS_CONFIG');
        try {
            putenv('INITIAL_SETTINGS_CONFIG=' . $this->path);
            $connection = Database::create();
            $repository = new SettingsRepository($connection);
            self::assertSame('admin', $repository->adminUsername());
            self::assertTrue(password_verify(password: $password[1], hash: $repository->load()->passwordHash));
            self::assertSame($settings->signingSecret, $repository->load()->secret);
            self::assertSame(hex2bin($settings->hmacSigningKey), $repository->load()->hmacKey);
        } finally {
            putenv(false === $previous ? 'INITIAL_SETTINGS_CONFIG' : 'INITIAL_SETTINGS_CONFIG=' . $previous);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function defaultFlows(): iterable
    {
        yield 'interactive defaults' => [true];
        yield 'noninteractive defaults' => [false];
    }

    public function testInteractiveQuestionsConfigureAllValuesWithoutOptions(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            'wizard-admin', 'https://example.com', 'UTC',
            ' chosen-password ', " original'\\secret ", str_repeat('AB', 32),
        ]);
        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        $settings = new InitialSettingsFile($this->path)->load();
        self::assertSame('wizard-admin', $settings->adminUsername);
        self::assertSame('https://example.com', $settings->baseUrl);
        self::assertSame('UTC', $settings->timezone);
        self::assertTrue(password_verify(password: ' chosen-password ', hash: $settings->passwordHash));
        self::assertSame(" original'\\secret ", $settings->signingSecret);
        self::assertSame(str_repeat('ab', 32), $settings->hmacSigningKey);
        foreach (['chosen-password', "original'\\secret", str_repeat('AB', 32)] as $secret) {
            self::assertStringNotContainsString($secret, $tester->getDisplay());
        }
        $output = $tester->getDisplay();
        self::assertMatchesRegularExpression(
            '/Admin username.*Base URL.*Timezone.*Admin password.*legacy signing secret.*HMAC key/s',
            $output,
        );
    }

    public function testHiddenCustomInputsAndNonSecretFlagsAreSavedWithoutEchoingSecrets(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['chosen-password', "original'\\secret", str_repeat('AB', 32)]);
        self::assertSame(0, $tester->execute([
            '--admin-username' => 'operator', '--base-url' => 'https://example.com', '--timezone' => 'UTC',
        ], ['interactive' => true]));
        $settings = new InitialSettingsFile($this->path)->load();
        self::assertSame('operator', $settings->adminUsername);
        self::assertSame('https://example.com', $settings->baseUrl);
        self::assertSame('UTC', $settings->timezone);
        self::assertSame("original'\\secret", $settings->signingSecret);
        self::assertSame(str_repeat('ab', 32), $settings->hmacSigningKey);
        self::assertTrue(password_verify(password: 'chosen-password', hash: $settings->passwordHash));
        foreach (['chosen-password', "original'\\secret", str_repeat('AB', 32), 'Generated password:'] as $secret) {
            self::assertStringNotContainsString($secret, $tester->getDisplay());
        }
        $before = file_get_contents($this->path);
        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('not been overwritten', $tester->getDisplay());
        self::assertStringNotContainsString('Generated password:', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testInvalidInputAndUnexpectedFailureCannotProduceCredentialsOrExposePrivateErrors(): void
    {
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['--base-url' => 'invalid'], ['interactive' => false]));
        self::assertFileDoesNotExist($this->path);
        self::assertStringNotContainsString('Generated password:', $tester->getDisplay());
        $store = $this->createMock(InitialSettingsStoreInterface::class);
        $store->expects($this->once())->method('create')
            ->willThrowException(new \RuntimeException('private path and credentials'));
        $tester = $this->tester($store);
        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringNotContainsString('private path and credentials', $tester->getDisplay());
        self::assertStringNotContainsString('Generated password:', $tester->getDisplay());
    }

    public function testMissingAndMalformedPrivateFilesAreRejected(): void
    {
        $file = new InitialSettingsFile($this->path);
        try {
            $file->load();
            self::fail('Missing initial settings must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Initial settings are missing. Run app:setup before database migrations.',
                $exception->getMessage(),
            );
        }
        file_put_contents(filename: $this->path, data: '<?php return ["admin_username" => 123];');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('Initial settings must contain all six string settings.');
        $file->load();
    }

    public function testSymlinksCannotBeOverwritten(): void
    {
        symlink(target: $this->directory . '/missing-target', link: $this->path);
        $this->expectException(InitialSettingsAlreadyExistException::class);
        $settings = new InitialSettingsFile(__DIR__ . '/../Fixtures/initial-settings.php')->load();
        new InitialSettingsFile($this->path)->create($settings);
    }

    public function testStorageCreatesPrivateParentDirectoryAndRefusesUnwritableTargets(): void
    {
        $settings = new InitialSettingsFile(__DIR__ . '/../Fixtures/initial-settings.php')->load();
        $file = new InitialSettingsFile($this->directory . '/private/initial-settings.php');
        self::assertSame($this->directory . '/private/initial-settings.php', $file->create($settings));
        self::assertSame(0700, (int) fileperms($this->directory . '/private') & 0777);
        $tester = $this->tester(new InitialSettingsFile('/proc/permit-setup-unwritable/initial-settings.php'));
        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringNotContainsString('/proc/permit-setup-unwritable', $tester->getDisplay());
    }
}
