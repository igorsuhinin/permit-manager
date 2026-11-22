<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response\ErrorHandler;
use App\Http\Translation\AdminLanguage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Translation\Translator;

#[Small]
#[CoversClass(ErrorHandler::class)]
#[UsesClass(AdminLanguage::class)]
final class ErrorHandlerTest extends TestCase
{
    public function testDetailsAreNeverRenderedEvenWhenRequestedByMiddleware(): void
    {
        $handler = new ErrorHandler(
            responses: new ResponseFactory(),
            language: new AdminLanguage(),
            translator: new Translator('en'),
            logger: new NullLogger(),
        );
        $response = $handler(
            request: new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/'),
            exception: new \RuntimeException('private diagnostic'),
            displayErrorDetails: true,
            logErrors: true,
            logErrorDetails: true,
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('private diagnostic', (string) $response->getBody());
    }

    public function testLoggerFailureStillReturnsSafeResponse(): void
    {
        $logger = new Logger(name: 'test', handlers: [new TestHandler()]);
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            throw new \RuntimeException('private logging configuration');
        });
        $handler = new ErrorHandler(
            responses: new ResponseFactory(),
            language: new AdminLanguage(),
            translator: new Translator('en'),
            logger: $logger,
        );
        $response = $handler(
            request: new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/'),
            exception: new \RuntimeException('private diagnostic'),
            displayErrorDetails: false,
            logErrors: true,
            logErrorDetails: true,
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('500 Internal Server Error', (string) $response->getBody());
        self::assertStringNotContainsString('private', (string) $response->getBody());
    }
}
