<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Command\PrepareInitialSettingsCommand;
use App\Application\Command\PrepareInitialSettingsHandler;
use App\Application\Configuration\InitialSettings;
use App\Application\Port\InitialSettingsStoreInterface;
use App\Application\Validator\InitialSettingsValidator;
use App\Application\View\PreparedInitialSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(PrepareInitialSettingsHandler::class)]
#[CoversClass(InitialSettingsValidator::class)]
#[UsesClass(PrepareInitialSettingsCommand::class)]
#[UsesClass(InitialSettings::class)]
#[UsesClass(PreparedInitialSettings::class)]
final class PrepareInitialSettingsHandlerTest extends TestCase
{
    public function testDefaultsGenerateIndependentCredentialsAndOnlyStoreThePasswordHash(): void
    {
        $saved = [];
        $store = $this->createMock(InitialSettingsStoreInterface::class);
        $store->expects($this->exactly(2))->method('create')->willReturnCallback(
            static function (InitialSettings $settings) use (&$saved): string {
                $saved[] = $settings;

                return '/private/initial-settings.php';
            },
        );
        $handler = new PrepareInitialSettingsHandler($store, new InitialSettingsValidator());
        $first = $handler->handle(new PrepareInitialSettingsCommand());
        $second = $handler->handle(new PrepareInitialSettingsCommand());
        self::assertSame('admin', $first->adminUsername);
        self::assertSame('/private/initial-settings.php', $first->path);
        self::assertIsString($first->generatedPassword);
        self::assertIsString($second->generatedPassword);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $first->generatedPassword);
        self::assertTrue(password_verify(password: $first->generatedPassword, hash: $saved[0]->passwordHash));
        self::assertNotSame($first->generatedPassword, $second->generatedPassword);
        self::assertNotSame($saved[0]->hmacSigningKey, $saved[1]->hmacSigningKey);
        self::assertNotSame($saved[0]->signingSecret, $saved[1]->signingSecret);
        self::assertNotSame($saved[0]->hmacSigningKey, $saved[0]->signingSecret);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $saved[0]->signingSecret);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $saved[0]->hmacSigningKey);
        self::assertSame('http://localhost:8080', $saved[0]->baseUrl);
        self::assertSame('Europe/Riga', $saved[0]->timezone);
        self::assertNotContains($first->generatedPassword, $saved[0]->values());
    }

    public function testExplicitValuesPreserveLegacySecretAndNormalizeOnlyTheHexKey(): void
    {
        $store = $this->createMock(InitialSettingsStoreInterface::class);
        $store->expects($this->once())->method('create')->willReturnCallback(
            static function (InitialSettings $settings): string {
                self::assertSame('operator', $settings->adminUsername);
                self::assertSame("original'\\secret", $settings->signingSecret);
                self::assertSame(str_repeat('ab', 32), $settings->hmacSigningKey);
                self::assertSame('https://example.com', $settings->baseUrl);
                self::assertSame('UTC', $settings->timezone);
                self::assertTrue(password_verify(password: 'chosen-password', hash: $settings->passwordHash));

                return '/private/settings.php';
            },
        );
        $result = new PrepareInitialSettingsHandler($store, new InitialSettingsValidator())->handle(
            new PrepareInitialSettingsCommand(
                adminUsername: 'operator',
                baseUrl: 'https://example.com',
                timezone: 'UTC',
                password: 'chosen-password',
                signingSecret: "original'\\secret",
                hmacSigningKey: str_repeat('AB', 32),
            ),
        );
        self::assertNull($result->generatedPassword);
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidSettingsCannotReachStorage(PrepareInitialSettingsCommand $command): void
    {
        $store = $this->createMock(InitialSettingsStoreInterface::class);
        $store->expects($this->never())->method('create');
        $this->expectException(\InvalidArgumentException::class);
        new PrepareInitialSettingsHandler($store, new InitialSettingsValidator())->handle($command);
    }

    /** @return iterable<string, array{PrepareInitialSettingsCommand}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty username' => [new PrepareInitialSettingsCommand(adminUsername: ' ')];
        yield 'long username' => [new PrepareInitialSettingsCommand(adminUsername: str_repeat('a', 256))];
        yield 'control username' => [new PrepareInitialSettingsCommand(adminUsername: "admin\n")];
        yield 'empty secret' => [new PrepareInitialSettingsCommand(signingSecret: '')];
        yield 'short key' => [new PrepareInitialSettingsCommand(hmacSigningKey: str_repeat('a', 63))];
        yield 'invalid timezone' => [new PrepareInitialSettingsCommand(timezone: 'Invalid/Timezone')];
        yield 'empty timezone' => [new PrepareInitialSettingsCommand(timezone: '')];
        foreach (
            ['invalid', 'ftp://example.com', 'https://user@example.com', 'https://example.com?q=1',
                'https://example.com#fragment'] as $url
        ) {
            yield $url => [new PrepareInitialSettingsCommand(baseUrl: $url)];
        }
    }
}
