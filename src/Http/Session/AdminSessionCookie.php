<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Application\Authentication\AdminSession;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class AdminSessionCookie
{
    public const string COOKIE = 'admin_session';

    public function __construct(private ClockInterface $clock) {}

    public function write(ResponseInterface $response, ?AdminSession $session, bool $secure): ResponseInterface
    {
        $age = null === $session ? 0 : max(0, $session->expiresAt - $this->clock->now()->getTimestamp());
        $cookie = self::COOKIE . '=' . ($session->token ?? '') . '; Path=/admin; Max-Age=' . $age
            . '; HttpOnly; SameSite=Strict';
        if ($secure) {
            $cookie .= '; Secure';
        }

        return $response->withAddedHeader(name: 'Set-Cookie', value: $cookie);
    }
}
