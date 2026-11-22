<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Authentication\AdminSession;
use App\Application\Authentication\AdminSessionService;
use App\Http\Session\AdminSessionCookie;
use App\Http\Translation\AdminLanguage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdminSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminSessionService $sessions,
        private AdminSessionCookie $cookies,
        private AdminLanguage $language,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @psalm-var mixed $token */
        $token = $request->getCookieParams()[AdminSessionCookie::COOKIE] ?? null;
        $session = $this->sessions->open(is_string($token) ? $token : null);
        $response = $handler->handle($request->withAttribute(AdminSession::class, $session));
        if ($token !== $session->token && !$response->hasHeader('Set-Cookie')) {
            $response = $this->cookies->write($response, $session, 'https' === $request->getUri()->getScheme());
        }

        $locale = $this->language->resolve($request);
        if (($request->getCookieParams()[AdminLanguage::COOKIE] ?? null) !== $locale) {
            $cookie = AdminLanguage::COOKIE . '=' . $locale
                . '; Path=/admin; Max-Age=31536000; HttpOnly; SameSite=Strict';
            if ('https' === $request->getUri()->getScheme()) {
                $cookie .= '; Secure';
            }
            $response = $response->withAddedHeader(name: 'Set-Cookie', value: $cookie);
        }

        return $response->withHeader(name: 'Content-Language', value: $locale)
            ->withHeader(name: 'Cache-Control', value: 'no-store')
            ->withHeader(name: 'X-Robots-Tag', value: 'noindex, nofollow');
    }
}
