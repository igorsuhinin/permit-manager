<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Http\Response\AdminNotice;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminNoticeCookie
{
    public const string COOKIE = 'admin_notice';

    public function __construct(private AdminSessionAccessor $sessions, private ClockInterface $clock) {}

    public function write(
        ServerRequestInterface $request,
        ResponseInterface $response,
        AdminNotice $notice,
    ): ResponseInterface {
        $payload = $notice->value . '|' . ($this->clock->now()->getTimestamp() + 60);
        $signature = hash_hmac(algo: 'sha256', data: $payload, key: $this->sessions->get($request)->token);

        return $this->cookie(request: $request, response: $response, value: $payload . '|' . $signature, age: 60);
    }

    public function read(ServerRequestInterface $request): ?AdminNotice
    {
        $value = $request->getCookieParams()[self::COOKIE] ?? null;
        if (
            !is_string($value)
            || 1 !== preg_match(
                pattern: '/\A([a-z._]+)\|([0-9]{10})\|([a-f0-9]{64})\z/',
                subject: $value,
                matches: $parts,
            )
        ) {
            return null;
        }
        $session = $this->sessions->get($request);
        $signature = hash_hmac(algo: 'sha256', data: $parts[1] . '|' . $parts[2], key: $session->token);
        if (
            !$session->authenticated || (int) $parts[2] <= $this->clock->now()->getTimestamp()
            || !hash_equals(known_string: $signature, user_string: $parts[3])
        ) {
            return null;
        }

        return AdminNotice::tryFrom($parts[1]);
    }

    public function clear(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->cookie(request: $request, response: $response, value: '', age: 0);
    }

    private function cookie(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $value,
        int $age,
    ): ResponseInterface {
        $cookie = self::COOKIE . '=' . $value . '; Path=/admin; Max-Age=' . $age . '; HttpOnly; SameSite=Strict';
        if ('https' === $request->getUri()->getScheme()) {
            $cookie .= '; Secure';
        }

        return $response->withAddedHeader(name: 'Set-Cookie', value: $cookie);
    }
}
