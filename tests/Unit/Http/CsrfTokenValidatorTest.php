<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Application\Authentication\AdminSession;
use App\Http\Request\Validator\CsrfTokenValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Small]
#[CoversClass(CsrfTokenValidator::class)]
#[UsesClass(AdminSession::class)]
final class CsrfTokenValidatorTest extends TestCase
{
    /** @return iterable<string, array{?array<string, mixed>}> */
    public static function invalidTokens(): iterable
    {
        yield 'missing body' => [null];
        yield 'missing token' => [[]];
        yield 'non-string token' => [['csrf' => ['token']]];
        yield 'wrong token' => [['csrf' => 'wrong']];
    }

    /** @param array<string, mixed>|null $body */
    #[DataProvider('invalidTokens')]
    public function testInvalidTokensAreRejectedAndLoggedWithoutRequestValues(?array $body): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Admin CSRF validation failed');
        $request = new ServerRequestFactory()->createServerRequest(method: 'POST', uri: '/admin/logout')
            ->withParsedBody($body);
        $this->expectException(HttpForbiddenException::class);
        new CsrfTokenValidator($logger)->validate($request, $this->session());
    }

    public function testMatchingTokenIsAcceptedWithoutWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $request = new ServerRequestFactory()->createServerRequest(method: 'POST', uri: '/admin/logout')
            ->withParsedBody(['csrf' => 'token']);
        new CsrfTokenValidator($logger)->validate($request, $this->session());
    }

    private function session(): AdminSession
    {
        return new AdminSession(
            token: 'session',
            csrfToken: 'token',
            authenticated: true,
            expiresAt: 1234,
            credentialHash: 'hash',
        );
    }
}
