<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const string NONCE_ATTRIBUTE = 'cspNonce';

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = base64_encode(random_bytes(32));
        $response = $handler->handle($request->withAttribute(self::NONCE_ATTRIBUTE, $nonce));

        // The datepicker positions its popup through style attributes; style elements still need a nonce.
        $policy = "default-src 'none'; script-src 'self' 'nonce-{$nonce}'; script-src-attr 'none'; "
            . "style-src 'self' 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src 'self' data:; "
            . "font-src 'self'; connect-src 'none'; object-src 'none'; frame-src 'none'; "
            . "frame-ancestors 'none'; base-uri 'none'; form-action 'self'";

        return $response
            ->withHeader(name: 'Cache-Control', value: 'no-store')
            ->withHeader(name: 'Referrer-Policy', value: 'no-referrer')
            ->withHeader(name: 'X-Content-Type-Options', value: 'nosniff')
            ->withHeader(name: 'X-Frame-Options', value: 'DENY')
            ->withHeader(name: 'Content-Security-Policy', value: $policy);
    }
}
