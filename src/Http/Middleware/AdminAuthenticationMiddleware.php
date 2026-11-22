<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session\AdminSessionAccessor;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdminAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminSessionAccessor $sessions,
        private ResponseFactoryInterface $responses,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->sessions->get($request)->authenticated) {
            return $this->responses->createResponse(303)->withHeader(name: 'Location', value: '/admin/login');
        }

        return $handler->handle($request);
    }
}
