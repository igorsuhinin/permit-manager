<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Port\LogContextInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;
use Tests\Support\Database;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class RequestCorrelationTest extends TestCase
{
    #[DataProvider('responses')]
    public function testResponseAndLogsShareAFreshId(string $method, string $path, int $status): void
    {
        $logs = new TestHandler();
        $app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$logs]),
            connection: Database::create(),
        );
        $app->get('/__failure', function (): never {
            throw new \RuntimeException('Synthetic failure');
        });
        $ids = [];
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $logs->clear();
            $request = new ServerRequestFactory()->createServerRequest(
                method: $method,
                uri: $path . '?sign=private-signature&id=private-permit',
            )->withHeader(name: 'X-Request-ID', value: 'attacker-chosen-id')
                ->withHeader(name: 'Authorization', value: 'Bearer private-token')
                ->withCookieParams(['admin_session' => 'private-cookie'])
                ->withParsedBody(['password' => 'private-password'])
                ->withAttribute(name: 'requestId', value: 'attacker-attribute');
            $response = $app->handle($request);
            self::assertSame($status, $response->getStatusCode());
            $id = $response->getHeaderLine('X-Request-ID');
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
            self::assertSame([$id], $response->getHeader('X-Request-ID'));
            self::assertStringNotContainsString($id, (string) $response->getBody());
            foreach ($logs->getRecords() as $record) {
                self::assertSame($id, $record->context['request_id']);
                $data = json_encode(value: $record->context, flags: JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('private-', $data);
                self::assertStringNotContainsString('attacker', $data);
            }
            /** @var LogContextInterface $context */
            $context = $app->getContainer()->get(LogContextInterface::class);
            self::assertNull($context->requestId());
            $ids[] = $id;
        }
        self::assertNotSame($ids[0], $ids[1]);
        /** @var LoggerInterface $logger */
        $logger = $app->getContainer()->get(LoggerInterface::class);
        $logger->info('Outside request');
        $record = array_last($logs->getRecords());
        self::assertNotNull($record);
        self::assertSame([], $record->context);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function responses(): iterable
    {
        yield 'success' => ['GET', '/health', 200];
        yield 'redirect' => ['GET', '/admin', 303];
        yield 'not found' => ['GET', '/missing', 404];
        yield 'method' => ['POST', '/health', 405];
        yield 'internal failure' => ['GET', '/__failure', 500];
        yield 'head' => ['HEAD', '/missing', 404];
    }

    public function testNativeStartupIdIsUsedOnce(): void
    {
        $app = ApplicationFactory::create(connection: Database::create(), requestId: str_repeat('a', 32));
        $request = new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/health');
        self::assertSame(str_repeat('a', 32), $app->handle($request)->getHeaderLine('X-Request-ID'));
        self::assertNotSame(str_repeat('a', 32), $app->handle($request)->getHeaderLine('X-Request-ID'));
    }

    public function testLoggerFailureFallbackIncludesTheResponseId(): void
    {
        $path = tempnam(directory: sys_get_temp_dir(), prefix: 'request-fallback-');
        self::assertNotFalse($path);
        $previous = ini_set(option: 'error_log', value: $path);
        $logger = new Logger(name: 'test', handlers: [new TestHandler()]);
        $logger->pushProcessor(static function (): never {
            throw new \RuntimeException('private-sink-details');
        });
        try {
            $app = ApplicationFactory::create(logger: $logger, connection: Database::create());
            $app->get('/__failure', function (): never {
                throw new \RuntimeException('Synthetic failure');
            });
            $response = $app->handle(new ServerRequestFactory()->createServerRequest(method: 'GET', uri: '/__failure'));
            self::assertSame(500, $response->getStatusCode());
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString('request_id=' . $response->getHeaderLine('X-Request-ID'), $contents);
            self::assertStringNotContainsString('private-sink-details', $contents);
        } finally {
            ini_set(option: 'error_log', value: (string) $previous);
            unlink($path);
        }
    }
}
