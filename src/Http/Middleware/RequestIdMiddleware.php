<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Port\LogContextInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'requestId';
    public const string HEADER = 'X-Request-ID';

    public function __construct(private LogContextInterface $context) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->context->begin();
        try {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE, $id))
                ->withHeader(name: self::HEADER, value: $id);
        } finally {
            $this->context->end();
        }
    }
}
