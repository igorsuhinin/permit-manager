<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Application\Authentication\AdminSession;
use App\Http\Response\AdminNotice;
use App\Http\Session\AdminNoticeCookie;
use App\Http\Session\AdminSessionAccessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Support\FrozenClock;

#[Small]
#[CoversClass(AdminNoticeCookie::class)]
#[UsesClass(AdminSession::class)]
#[UsesClass(AdminSessionAccessor::class)]
final class AdminNoticeCookieTest extends TestCase
{
    private function cookies(int $offset = 0): AdminNoticeCookie
    {
        return new AdminNoticeCookie(
            new AdminSessionAccessor(),
            new FrozenClock(new \DateTimeImmutable('2026-11-11T12:00:00Z')->modify('+' . $offset . ' seconds')),
        );
    }

    private function request(bool $authenticated = true, string $token = 'test-session'): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest(
            method: 'GET',
            uri: 'https://example.com/admin/blacklist',
        )
            ->withAttribute(AdminSession::class, new AdminSession(
                token: $token,
                csrfToken: 'test-csrf',
                authenticated: $authenticated,
                expiresAt: 1794399000,
                credentialHash: 'test-credentials',
            ));
    }

    public function testSignedNoticeSurvivesRedirectAndIsBoundToTheAuthenticatedSession(): void
    {
        $request = $this->request();
        $response = $this->cookies()->write($request, new Response(), AdminNotice::BlacklistAdded);
        $header = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('; Path=/admin; Max-Age=60; HttpOnly; SameSite=Strict; Secure', $header);
        $value = substr(explode(separator: ';', string: $header)[0], strlen(AdminNoticeCookie::COOKIE) + 1);
        $request = $request->withCookieParams([AdminNoticeCookie::COOKIE => $value]);
        self::assertSame(AdminNotice::BlacklistAdded, $this->cookies()->read($request));
        self::assertSame(AdminNotice::BlacklistAdded, $this->cookies(59)->read($request));
        self::assertNull($this->cookies(60)->read($request));
        self::assertNull($this->cookies()->read(
            $this->request(token: 'another-session')->withCookieParams($request->getCookieParams()),
        ));
        self::assertNull($this->cookies()->read(
            $this->request(false)->withCookieParams($request->getCookieParams()),
        ));
        self::assertNull($this->cookies()->read($request->withCookieParams([
            AdminNoticeCookie::COOKIE => str_replace(
                search: 'blacklist_added',
                replace: 'blacklist_removed',
                subject: $value,
            ),
        ])));
        self::assertSame(
            'admin_notice=; Path=/admin; Max-Age=0; HttpOnly; SameSite=Strict; Secure',
            $this->cookies()->clear($request, new Response())->getHeaderLine('Set-Cookie'),
        );
    }

    public function testUnknownSignedNoticeAndMissingCookieAreIgnored(): void
    {
        self::assertNull($this->cookies()->read($this->request()));
        $payload = 'notice.unknown|1794398460';
        $signature = hash_hmac(algo: 'sha256', data: $payload, key: 'test-session');
        self::assertNull($this->cookies()->read($this->request()->withCookieParams([
            AdminNoticeCookie::COOKIE => $payload . '|' . $signature,
        ])));
        $request = $this->request()->withUri($this->request()->getUri()->withScheme('http'));
        $response = $this->cookies()->write($request, new Response(), AdminNotice::BlacklistRemoved);
        self::assertStringNotContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
    }

    #[DataProvider('invalidCookies')]
    public function testMalformedCookieIsIgnored(mixed $value): void
    {
        $request = $this->request()->withCookieParams([AdminNoticeCookie::COOKIE => $value]);
        self::assertNull($this->cookies()->read($request));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCookies(): iterable
    {
        yield 'array' => [[]];
        yield 'empty' => [''];
        yield 'plain text' => ['notice.blacklist_added'];
        yield 'untrusted HTML' => ['<script>alert(1)</script>'];
        yield 'invalid signature' => ['notice.blacklist_added|1794398460|' . str_repeat('a', 64)];
    }
}
