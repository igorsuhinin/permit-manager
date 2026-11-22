<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Application\Port\AdminSessionRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class AdminSessionService
{
    public function __construct(
        private AdminSessionRepositoryInterface $sessions,
        private AdminCredentials $credentials,
        private ClockInterface $clock,
    ) {}

    public function open(?string $token): AdminSession
    {
        $now = $this->clock->now()->getTimestamp();
        if (null !== $token && 1 === preg_match(pattern: '/\A[a-f0-9]{64}\z/', subject: $token)) {
            $session = $this->sessions->find($token);
            if (
                null !== $session && $session->expiresAt > $now
                && (!$session->authenticated || $session->credentialHash === $this->credentials->fingerprint())
            ) {
                return $session;
            }
            $this->sessions->remove($token);
        }

        return $this->create(false);
    }

    public function login(AdminSession $previous): AdminSession
    {
        $session = $this->create(true);
        $this->sessions->remove($previous->token);

        return $session;
    }

    public function logout(AdminSession $session): void
    {
        $this->sessions->remove($session->token);
    }

    private function create(bool $authenticated): AdminSession
    {
        $now = $this->clock->now()->getTimestamp();
        $session = new AdminSession(
            token: bin2hex(random_bytes(32)),
            csrfToken: bin2hex(random_bytes(32)),
            authenticated: $authenticated,
            expiresAt: $now + ($authenticated ? 3600 : 600),
            credentialHash: $authenticated ? $this->credentials->fingerprint() : null,
        );
        $this->sessions->save($session, $now);

        return $session;
    }
}
