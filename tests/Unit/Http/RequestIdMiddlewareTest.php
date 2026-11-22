<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\RequestIdMiddleware;
use App\Infrastructure\Logging\RequestLogContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Small]
#[CoversClass(RequestIdMiddleware::class)]
#[UsesClass(RequestLogContext::class)]
final class RequestIdMiddlewareTest extends TestCase
{
    public function testHandlerSeesScopedIdAndCannotOverrideResponseHeader(): void
    {
        $context = new RequestLogContext(str_repeat('a', 32));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $request) use ($context) {
                self::assertSame($context->requestId(), $request->getAttribute(RequestIdMiddleware::ATTRIBUTE));

                return new ResponseFactory()->createResponse()->withHeader(name: 'X-Request-ID', value: 'spoofed');
            },
        );
        $request = new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/')
            ->withAttribute(name: RequestIdMiddleware::ATTRIBUTE, value: 'spoofed');
        $response = new RequestIdMiddleware($context)->process($request, $handler);
        self::assertSame([str_repeat('a', 32)], $response->getHeader('X-Request-ID'));
        self::assertNull($context->requestId());
    }

    public function testEscapingFailureStillClosesScope(): void
    {
        $context = new RequestLogContext();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willThrowException(new \RuntimeException('Synthetic'));
        $this->expectException(\RuntimeException::class);
        try {
            new RequestIdMiddleware($context)->process(
                new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/'),
                $handler,
            );
        } finally {
            self::assertNull($context->requestId());
        }
    }
}
