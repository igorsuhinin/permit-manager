<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Infrastructure\Logging\SentryIntegration;
use App\Infrastructure\Logging\SentryPrivacy;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\EventType;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;
use Tests\Support\Database;
use Tests\Support\RecordingSentryHttpClient;
use Tests\Support\RecordingSentryTransport;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class SentryTest extends TestCase
{
    private RecordingSentryTransport $transport;
    private TestHandler $local;
    private Logger $logger;

    #[\Override]
    protected function setUp(): void
    {
        SentrySdk::init();
        $this->transport = new RecordingSentryTransport();
        $this->local = new TestHandler();
        $this->logger = new Logger(name: 'test', handlers: [$this->local]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->transport->fail = false;
        $this->logger->close();
        SentryIntegration::flush();
        SentrySdk::init();
    }

    /** @param array<string, mixed> $options */
    private function configure(array $options = []): void
    {
        new SentryIntegration(new SentryPrivacy(dirname(__DIR__, 2)))->configure($this->logger, $options + [
            'dsn' => 'https://public@example.invalid/1',
            'environment' => 'test',
            'release' => 'permit-manager@test',
            'transport' => $this->transport,
        ]);
    }

    public function testIssuesAndStructuredLogsAreCorrelatedAndScrubbedBeforeTransport(): void
    {
        $this->configure();
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope): void {
            $scope->setUser(['id' => 'private-user', 'email' => 'private@example.invalid']);
            $scope->setTag(key: 'private-tag', value: 'private-value');
            $scope->setExtra(key: 'private-extra', value: 'private-value');
            $scope->addBreadcrumb(new Breadcrumb(
                level: Breadcrumb::LEVEL_INFO,
                type: Breadcrumb::TYPE_DEFAULT,
                category: 'private-category',
                message: 'private-breadcrumb',
            ));
        });
        $context = [
            'request_id' => str_repeat('a', 32), 'guest' => true, 'status' => 500, 'method' => 'POST',
            'password' => 'private-password', 'id_hash' => 'private-hash', 'permit_id' => 'private-permit',
            'path' => '/private-route', 'query' => 'sign=private-signature', 'cookie' => 'private-cookie',
        ];
        $failure = new \RuntimeException('private exception with SQL and signing keys');
        $this->logger->error('HTTP request failed', $context + ['exception' => $failure]);
        $this->logger->info('Permit generated', $context);
        $this->logger->warning('private dynamic message', $context);
        $this->logger->close();
        self::assertCount(2, $this->transport->events);
        $issue = $this->transport->events[0];
        self::assertSame(EventType::event(), $issue->getType());
        self::assertSame(str_repeat('a', 32), $issue->getTags()['request_id']);
        self::assertSame('RuntimeException', $issue->getExceptions()[0]->getType());
        self::assertNotNull($issue->getExceptions()[0]->getStacktrace());
        self::assertSame([], $issue->getRequest());
        self::assertNull($issue->getUser());
        self::assertNull($issue->getServerName());
        self::assertSame(EventType::logs(), $this->transport->events[1]->getType());
        self::assertCount(2, $this->transport->events[1]->getLogs());
        foreach ($this->transport->events[1]->getLogs() as $log) {
            self::assertSame($context['request_id'], $log->attributes()->get('request_id')?->getValue());
        }
        $client = SentrySdk::getCurrentHub()->getClient();
        self::assertNotNull($client);
        $http = new RecordingSentryHttpClient();
        $wireClient = ClientBuilder::create([
            'dsn' => 'https://public@example.invalid/1',
            'default_integrations' => false,
            'http_client' => $http,
        ])->getClient();
        foreach ($this->transport->events as $event) {
            $wireClient->captureEvent($event);
        }
        $wireClient->flush();
        self::assertCount(2, $http->payloads);
        foreach ($http->payloads as $payload) {
            self::assertStringNotContainsString('private-', $payload);
            self::assertStringNotContainsString('private@', $payload);
            self::assertStringNotContainsString('/var/www', $payload);
            self::assertStringNotContainsString('"vars"', $payload);
            self::assertStringNotContainsString('server.address', $payload);
        }
        self::assertSame($failure, $this->local->getRecords()[0]->context['exception']);
        self::assertSame('private-password', $this->local->getRecords()[0]->context['password']);
    }

    public function testOptionalSdkCannotEnableRequestCollectionOrProfilingThroughOptions(): void
    {
        $this->configure([
            'default_integrations' => true, 'send_default_pii' => true,
            'traces_sample_rate' => 1.0, 'profiles_sample_rate' => 1.0,
        ]);
        $client = SentrySdk::getCurrentHub()->getClient();
        self::assertNotNull($client);
        self::assertFalse($client->getOptions()->shouldSendDefaultPii());
        self::assertSame(0.0, $client->getOptions()->getTracesSampleRate());
        self::assertSame(0.0, $client->getOptions()->getProfilesSampleRate());
        self::assertSame(0, $client->getOptions()->getContextLines());
    }

    public function testDisabledSentryAddsNoHandlersAndDoesNotInitializeClient(): void
    {
        $this->configure(['dsn' => null]);
        self::assertNull(SentrySdk::getCurrentHub()->getClient());
        self::assertCount(1, $this->logger->getHandlers());
        $this->logger->info('Permit generated');
        SentryIntegration::flush();
        self::assertTrue($this->local->hasInfoRecords());
        self::assertSame([], $this->transport->events);
    }

    public function testRemoteTransportFailurePreservesLocalLogging(): void
    {
        $this->configure();
        $this->transport->fail = true;
        $this->logger->error('HTTP request failed', ['exception' => new \RuntimeException('Synthetic error')]);
        $this->logger->info('Permit generated');
        SentryIntegration::flush();
        self::assertTrue($this->local->hasRecords(Level::Error));
        self::assertTrue($this->local->hasRecords(Level::Info));
    }

    public function testMalformedDsnCannotBreakPrimaryLoggingOrLeakItsValue(): void
    {
        $this->configure(['dsn' => 'private-invalid-dsn']);
        self::assertCount(1, $this->logger->getHandlers());
        $this->logger->info('Permit generated');
        self::assertTrue($this->local->hasInfoRecords());
    }

    public function testHttpResponseIdMatchesTheSentryIssueAndLocalRecord(): void
    {
        $this->configure();
        $app = ApplicationFactory::create(logger: $this->logger, connection: Database::create());
        $app->get('/__failure', function (): never {
            throw new \RuntimeException('private-runtime-details');
        });
        $response = $app->handle(new ServerRequestFactory()->createServerRequest(
            method: 'GET',
            uri: '/__failure?sign=private-signature',
        ));
        self::assertSame(500, $response->getStatusCode());
        $id = $response->getHeaderLine('X-Request-ID');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
        self::assertCount(1, $this->transport->events);
        self::assertSame($id, $this->transport->events[0]->getTags()['request_id']);
        self::assertSame($id, $this->local->getRecords()[0]->context['request_id']);
        self::assertStringNotContainsString('private-runtime-details', (string) $response->getBody());
    }
}
