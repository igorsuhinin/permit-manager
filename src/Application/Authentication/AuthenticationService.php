<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Application\Audit\AdminAudit;
use App\Application\Enum\AdminAuditAction;
use App\Application\Port\TransactionInterface;
use App\Application\View\LoginResult;
use Psr\Log\LoggerInterface;

final readonly class AuthenticationService
{
    public function __construct(
        private AdminCredentials $credentials,
        private AdminSessionService $sessions,
        private LoginRateLimiter $limiter,
        private AdminAudit $audit,
        private TransactionInterface $transactions,
        private LoggerInterface $logger,
    ) {}

    public function login(AdminSession $session, string $username, string $password, string $address): LoginResult
    {
        $retry = $this->limiter->retryAfter($address);
        if ($retry > 0) {
            return new LoginResult(retryAfter: $retry);
        }
        if ($this->credentials->matches(username: $username, password: $password)) {
            $authenticated = $this->transactions->run(function () use ($address, $session): AdminSession {
                $this->limiter->clear($address);
                $authenticated = $this->sessions->login($session);
                $this->audit->record(
                    actor: $this->credentials->username,
                    action: AdminAuditAction::Login,
                    targetHash: hash(algo: 'sha256', data: $this->credentials->username),
                );

                return $authenticated;
            });
            $this->logger->info('Admin login succeeded');

            return new LoginResult($authenticated);
        }
        $this->limiter->fail($address);
        $this->logger->warning('Admin login failed');

        return new LoginResult();
    }

    public function username(): string
    {
        return $this->credentials->username;
    }

    public function logout(AdminSession $session): void
    {
        $this->transactions->run(function () use ($session): void {
            $this->sessions->logout($session);
            $this->audit->record(
                actor: $this->credentials->username,
                action: AdminAuditAction::Logout,
                targetHash: hash(algo: 'sha256', data: $this->credentials->username),
            );
        });
        $this->logger->info('Admin logged out');
    }
}
