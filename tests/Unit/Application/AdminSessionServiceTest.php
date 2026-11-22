<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Authentication\AdminCredentials;
use App\Application\Authentication\AdminSession;
use App\Application\Authentication\AdminSessionService;
use App\Application\Port\AdminSessionRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

#[Small]
#[CoversClass(AdminSessionService::class)]
#[UsesClass(AdminSession::class)]
#[UsesClass(AdminCredentials::class)]
final class AdminSessionServiceTest extends TestCase
{
    public function testOpeningLoginAndLogoutPreserveSessionLifetimesAndRotation(): void
    {
        $time = new \DateTimeImmutable('2026-11-03T12:00:00Z');
        $store = $this->createMock(AdminSessionRepositoryInterface::class);
        $saved = [];
        $store->expects($this->exactly(2))->method('save')->willReturnCallback(static function (
            AdminSession $session,
            int $now,
        ) use (
            &$saved,
            $time
        ): void {
            self::assertSame($time->getTimestamp(), $now);
            $saved[] = $session;
        });
        $removed = [];
        $store->expects($this->exactly(2))->method('remove')
            ->willReturnCallback(static function (string $token) use (&$removed): void {
                $removed[] = $token;
            });
        $credentials = new AdminCredentials(username: 'admin', passwordHash: 'hash');
        $service = new AdminSessionService($store, $credentials, new FrozenClock($time));
        $anonymous = $service->open(null);
        self::assertFalse($anonymous->authenticated);
        self::assertNull($anonymous->credentialHash);
        self::assertSame($time->getTimestamp() + 600, $anonymous->expiresAt);
        $authenticated = $service->login($anonymous);
        self::assertTrue($authenticated->authenticated);
        self::assertSame($credentials->fingerprint(), $authenticated->credentialHash);
        self::assertSame($time->getTimestamp() + 3600, $authenticated->expiresAt);
        foreach ([$anonymous, $authenticated] as $session) {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $session->token);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $session->csrfToken);
        }
        self::assertNotSame($anonymous->token, $authenticated->token);
        self::assertNotSame($anonymous->csrfToken, $authenticated->csrfToken);
        $service->logout($authenticated);
        self::assertSame([$anonymous, $authenticated], $saved);
        self::assertSame([$anonymous->token, $authenticated->token], $removed);
    }

    #[DataProvider('existingSessions')]
    public function testExistingSessionChecksExpiryAndCredentialChanges(
        bool $authenticated,
        int $remaining,
        bool $matchingCredentials,
        bool $accepted,
    ): void {
        $time = new \DateTimeImmutable('2026-11-03T12:00:00Z');
        $token = str_repeat('a', 64);
        $credentials = new AdminCredentials(username: 'admin', passwordHash: 'hash');
        $old = new AdminSession(
            token: $token,
            csrfToken: str_repeat('b', 64),
            authenticated: $authenticated,
            expiresAt: $time->getTimestamp() + $remaining,
            credentialHash: $matchingCredentials ? $credentials->fingerprint() : null,
        );
        $store = $this->createMock(AdminSessionRepositoryInterface::class);
        $store->expects($this->once())->method('find')->with($token)->willReturn($old);
        $store->expects($accepted ? $this->never() : $this->once())->method('remove')->with($token);
        $store->expects($accepted ? $this->never() : $this->once())->method('save');
        $session = new AdminSessionService($store, $credentials, new FrozenClock($time))->open($token);
        if ($accepted) {
            self::assertSame($old, $session);
        } else {
            self::assertNotSame($old, $session);
            self::assertFalse($session->authenticated);
        }
    }

    /** @return iterable<string, array{bool, int, bool, bool}> */
    public static function existingSessions(): iterable
    {
        yield 'anonymous' => [false, 1, false, true];
        yield 'authenticated' => [true, 1, true, true];
        yield 'exact expiry' => [true, 0, true, false];
        yield 'expired' => [true, -1, true, false];
        yield 'changed credentials' => [true, 100, false, false];
    }
}
