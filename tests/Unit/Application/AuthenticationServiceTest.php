<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Audit\AdminAudit;
use App\Application\Authentication\AdminCredentials;
use App\Application\Authentication\AdminSession;
use App\Application\Authentication\AdminSessionService;
use App\Application\Authentication\AuthenticationService;
use App\Application\Authentication\LoginRateLimiter;
use App\Application\Port\AdminAuditTrailInterface;
use App\Application\Port\AdminSessionRepositoryInterface;
use App\Application\Port\LoginAttemptStoreInterface;
use App\Application\View\AdminAuditEntry;
use App\Application\View\LoginResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Support\FrozenClock;
use Tests\Support\ImmediateTransaction;

#[Small]
#[UsesClass(AdminAudit::class)]
#[UsesClass(AdminAuditEntry::class)]
#[CoversClass(AuthenticationService::class)]
#[UsesClass(AdminCredentials::class)]
#[UsesClass(AdminSession::class)]
#[UsesClass(AdminSessionService::class)]
#[UsesClass(LoginRateLimiter::class)]
#[CoversClass(LoginResult::class)]
final class AuthenticationServiceTest extends TestCase
{
    private function audit(): AdminAudit
    {
        return new AdminAudit(
            self::createStub(AdminAuditTrailInterface::class),
            new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
        );
    }

    public function testAuthenticationUsesStoragePortsAndRecordsSuccessfulLoginAndLogout(): void
    {
        $credentials = new AdminCredentials(
            username: 'admin',
            passwordHash: password_hash(password: 'password', algo: PASSWORD_BCRYPT, options: ['cost' => 4]),
        );
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-03'));
        $attempts = $this->createMock(LoginAttemptStoreInterface::class);
        $attempts->expects($this->once())->method('find')->willReturn(null);
        $attempts->expects($this->once())->method('clear')->with(hash(algo: 'sha256', data: '192.0.2.1'));
        $store = $this->createMock(AdminSessionRepositoryInterface::class);
        $store->expects($this->once())->method('save');
        $store->expects($this->exactly(2))->method('remove');
        $logger = $this->createMock(LoggerInterface::class);
        $messages = [];
        $logger->expects($this->exactly(2))->method('info')->willReturnCallback(static function (
            string $message,
            array $context = [],
        ) use (&$messages): void {
            self::assertSame([], $context);
            $messages[] = $message;
        });
        $service = new AuthenticationService(
            credentials: $credentials,
            sessions: new AdminSessionService($store, $credentials, $clock),
            limiter: new LoginRateLimiter($attempts, $clock),
            audit: $this->audit(),
            transactions: new ImmediateTransaction(),
            logger: $logger,
        );
        $result = $service->login(
            session: new AdminSession(
                token: str_repeat('a', 64),
                csrfToken: str_repeat('b', 64),
                authenticated: false,
                expiresAt: 0,
                credentialHash: null,
            ),
            username: 'admin',
            password: 'password',
            address: '192.0.2.1',
        );
        self::assertSame(0, $result->retryAfter);
        self::assertNotNull($result->session);
        self::assertTrue($result->session->authenticated);
        self::assertSame('admin', $service->username());
        $service->logout($result->session);
        self::assertSame(['Admin login succeeded', 'Admin logged out'], $messages);
    }
}
