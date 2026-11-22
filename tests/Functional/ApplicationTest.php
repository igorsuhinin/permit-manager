<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Port\QrCodeGeneratorInterface;
use App\Http\Translation\PublicUi;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\PermitCheckRepository;
use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Support\ApplicationFactory;
use Tests\Support\Database;
use Tests\Support\FrozenClock;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class ApplicationTest extends TestCase
{
    // Original signature for the synthetic resident permit 26/1.
    private const string LEGACY_RESIDENT_SIGNATURE = '19337794d774e807fce07ff2b42320be';

    /** @var App<Container> */
    private App $app;
    private TestHandler $logs;
    private Connection $connection;
    /** @var array<string, string> */
    private array $cookies = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        $this->connection = Database::create();
        $this->app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$this->logs]),
            connection: $this->connection,
        );
        $container = $this->app->getContainer();
        $container->set(ClockInterface::class, new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')));
    }

    /** @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): ResponseInterface
    {
        $request = new ServerRequestFactory()->createServerRequest(
            method: $method,
            uri: $path,
        )->withQueryParams($query)->withCookieParams($this->cookies);
        if (null !== $body) {
            $request = $request->withParsedBody($body);
        }

        $response = $this->app->handle($request);
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = explode(separator: '=', string: explode(separator: ';', string: $header)[0], limit: 2);
            $name = $cookie[0];
            $value = $cookie[1] ?? self::fail('The session cookie has no value.');
            $this->cookies[$name] = $value;
        }

        return $response;
    }

    public function testHomeAndHealth(): void
    {
        $home = $this->request(method: 'GET', path: '/');
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('<h1>Welcome</h1>', (string) $home->getBody());
        $health = $this->request(method: 'GET', path: '/health');
        self::assertSame('application/json', $health->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok"}', (string) $health->getBody());
    }

    public function testAdminAuditRecordsOnlyCommittedChangesWithTheTrustedActor(): void
    {
        $csrf = $this->authorize();
        $data = [
            'csrf' => $csrf, 'id' => 'DEMO-AUDIT', 'plate' => 'AB1234', 'apt' => '48', 'term' => '31.12.2099',
            'actor' => 'untrusted-actor',
        ];
        foreach ([1, 2] as $attempt) {
            self::assertSame(200, $this->request(method: 'POST', path: '/admin/permits/generate', body: $data)
                ->getStatusCode());
        }
        $fingerprint = $this->connection->fetchOne('SELECT fingerprint FROM permits');
        self::assertIsString($fingerprint);
        self::assertSame(200, $this->request(
            method: 'POST',
            path: '/admin/permits/' . $fingerprint . '/reprint',
            body: ['csrf' => $csrf],
        )->getStatusCode());
        $change = ['csrf' => $csrf, 'id' => 'DEMO-AUDIT', 'reason' => 'private reason', 'actor' => 'untrusted-actor'];
        foreach (['add' => 303, 'duplicate' => 409, 'remove' => 303, 'no-op' => 303] as $action => $status) {
            self::assertSame($status, $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: $change + ['action' => in_array($action, ['add', 'duplicate'], true) ? 'add' : 'remove'],
            )->getStatusCode());
        }
        self::assertSame(303, $this->request(
            method: 'POST',
            path: '/admin/logout',
            body: ['csrf' => $csrf],
        )->getStatusCode());
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM admin_audit ORDER BY id');
        self::assertSame(
            ['login', 'permit_issued', 'blacklist_added', 'blacklist_removed', 'logout'],
            array_column($rows, 'action'),
        );
        self::assertSame(array_fill(start_index: 0, count: 5, value: 'admin'), array_column($rows, 'actor'));
        self::assertSame(
            array_fill(start_index: 0, count: 5, value: '2026-11-03 10:00:00'),
            array_column($rows, 'occurred_at'),
        );
        self::assertSame([
            hash(algo: 'sha256', data: 'admin'), $fingerprint, hash(algo: 'sha256', data: 'DEMO-AUDIT'),
            hash(algo: 'sha256', data: 'DEMO-AUDIT'), hash(algo: 'sha256', data: 'admin'),
        ], array_column($rows, 'target_hash'));
        self::assertSame(['id', 'actor', 'action', 'target_hash', 'occurred_at'], array_keys($rows[0]));
    }

    #[DataProvider('postCommitFailures')]
    public function testPostCommitFailureIsPrivateAndRetryDoesNotDuplicateIssuance(string $failure): void
    {
        $container = $this->app->getContainer();
        /** @var \ArrayObject<string, bool> $state */
        $state = new \ArrayObject(['active' => true]);
        /** @var Environment $twig */
        $twig = $container->get(Environment::class);
        $loader = $twig->getLoader();
        if ('qr' === $failure) {
            /** @var QrCodeGeneratorInterface $original */
            $original = $container->get(QrCodeGeneratorInterface::class);
            $qr = $this->createMock(QrCodeGeneratorInterface::class);
            $qr->expects($this->exactly(2))->method('dataUri')->willReturnCallback(
                static function (string $url) use ($state, $original): string {
                    if (true === $state['active']) {
                        throw new \RuntimeException('private-qr-failure');
                    }

                    return $original->dataUri($url);
                },
            );
            $container->set(QrCodeGeneratorInterface::class, $qr);
        } elseif ('logger' === $failure) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->exactly(3))->method('info')->willReturnCallback(
                static function (string $message) use ($state): void {
                    if ('Permit generated' === $message && true === $state['active']) {
                        throw new \RuntimeException('private-log-failure');
                    }
                },
            );
            $logger->expects($this->once())->method('log')->willReturnCallback(
                new Logger(name: 'test', handlers: [$this->logs])->log(...),
            );
            $container->set(LoggerInterface::class, $logger);
        } else {
            $twig->setLoader(new ChainLoader([new ArrayLoader([
                'permit.html.twig' => '{{ private_missing_variable }}',
            ]), $loader]));
        }
        $csrf = $this->authorize();
        $data = ['csrf' => $csrf, 'id' => 'DEMO-RETRY', 'plate' => 'AB1234', 'apt' => '48', 'term' => '31.12.2099'];
        $failed = $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(500, $failed->getStatusCode());
        self::assertSame('no-store', $failed->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('private-', (string) $failed->getBody());
        self::assertStringNotContainsString('RuntimeException', (string) $failed->getBody());
        self::assertTrue($this->logs->hasRecords(Level::Error));
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM permits');
        $audit = $this->connection->fetchAllAssociative('SELECT * FROM admin_audit ORDER BY id');
        self::assertCount(1, $rows);
        self::assertSame(['login', 'permit_issued'], array_column($audit, 'action'));
        $state['active'] = false;
        $twig->setLoader($loader);
        $retried = $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(200, $retried->getStatusCode());
        self::assertStringContainsString('data:image/svg+xml;base64,', (string) $retried->getBody());
        self::assertSame($rows, $this->connection->fetchAllAssociative('SELECT * FROM permits'));
        self::assertSame($audit, $this->connection->fetchAllAssociative('SELECT * FROM admin_audit ORDER BY id'));
    }

    /** @return iterable<string, array{string}> */
    public static function postCommitFailures(): iterable
    {
        yield 'QR encoder' => ['qr'];
        yield 'operational logger' => ['logger'];
        yield 'print template' => ['template'];
    }

    #[DataProvider('auditedWrites')]
    public function testUnavailableAuditRollsBackTheAdminChange(string $action): void
    {
        $csrf = $this->authorize();
        $this->connection->executeStatement('DROP TABLE admin_audit');
        $sessions = $this->connection->fetchAllAssociative('SELECT * FROM admin_sessions ORDER BY token_hash');
        $blacklist = $this->connection->fetchAllAssociative('SELECT * FROM blacklisted_ids ORDER BY id_hash');
        $path = '/admin/blacklist';
        $data = ['csrf' => $csrf, 'action' => $action, 'id' => 'DEMO-BLOCKED-1', 'reason' => ''];
        if ('add' === $action) {
            $data['id'] = 'DEMO-NEW';
        } elseif ('issue' === $action) {
            $path = '/admin/permits/generate';
            $data += ['plate' => 'AB1234', 'apt' => '48', 'term' => '31.12.2099'];
        } elseif ('logout' === $action) {
            $path = '/admin/logout';
        } elseif ('login' === $action) {
            $this->cookies = [];
            $form = $this->request(method: 'GET', path: '/admin/login');
            self::assertSame(1, preg_match(
                pattern: '/name="csrf" value="([a-f0-9]{64})"/',
                subject: (string) $form->getBody(),
                matches: $m,
            ));
            $data = ['csrf' => $m[1], 'username' => 'admin', 'password' => 'test-password'];
            $path = '/admin/login';
            $sessions = $this->connection->fetchAllAssociative('SELECT * FROM admin_sessions ORDER BY token_hash');
        }
        $response = $this->request(method: 'POST', path: $path, body: $data);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('admin_audit', (string) $response->getBody());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame($blacklist, $this->connection->fetchAllAssociative(
            'SELECT * FROM blacklisted_ids ORDER BY id_hash',
        ));
        self::assertSame($sessions, $this->connection->fetchAllAssociative(
            'SELECT * FROM admin_sessions ORDER BY token_hash',
        ));
        self::assertFalse($this->connection->isTransactionActive());
        $check = $this->request(method: 'GET', path: '/p/check', query: [
            'id' => 'DEMO-OLD', 'term' => '31.12.2099', 'plate' => 'AB1234',
            'sign' => 'e9204f7fac19a47a985fc87998b7820b',
        ]);
        self::assertSame(200, $check->getStatusCode());
        self::assertStringContainsString('Ir derīga', (string) $check->getBody());
    }

    /** @return iterable<array{string}> */
    public static function auditedWrites(): iterable
    {
        foreach (['issue', 'add', 'remove', 'login', 'logout'] as $action) {
            yield [$action];
        }
    }

    #[DataProvider('securedResponses')]
    public function testSecurityHeadersCoverAllDynamicResponses(string $method, string $path, int $status): void
    {
        $query = [
            'id' => 'DEMO-HEADERS', 'term' => '03.11.2026', 'plate' => 'AB1234',
            'sign' => '507b02e445fc0995bfbaadee573bfc37',
        ];
        $response = $this->request(method: $method, path: $path, query: $query);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $policy = $response->getHeaderLine('Content-Security-Policy');
        self::assertSame(1, preg_match(pattern: "/script-src 'self' 'nonce-([^']+)'/", subject: $policy, matches: $m));
        self::assertSame(32, strlen((string) base64_decode(string: $m[1], strict: true)));
        self::assertStringContainsString("style-src 'self' 'nonce-{$m[1]}'", $policy);
        self::assertStringContainsString("script-src-attr 'none'", $policy);
        self::assertStringContainsString("style-src-attr 'unsafe-inline'", $policy);
        self::assertStringContainsString("img-src 'self' data:", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("base-uri 'none'; form-action 'self'", $policy);
        if ('GET' === $method && str_starts_with(haystack: $path, needle: '/p/')) {
            self::assertStringContainsString('nonce="' . $m[1] . '"', (string) $response->getBody());
        }
        $next = $this->request(method: $method, path: $path, query: $query);
        self::assertNotSame($policy, $next->getHeaderLine('Content-Security-Policy'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function securedResponses(): iterable
    {
        yield 'landing page' => ['GET', '/', 200];
        yield 'JSON liveness' => ['GET', '/health', 200];
        yield 'valid legacy URL' => ['GET', '/p/check', 200];
        yield 'valid PHP URL' => ['GET', '/p/check.php', 200];
        yield 'HEAD check' => ['HEAD', '/p/check', 200];
        yield 'login' => ['GET', '/admin/login', 200];
        yield 'admin redirect' => ['GET', '/admin/permits', 303];
        yield 'legacy redirect' => ['GET', '/permit', 303];
        yield 'missing page' => ['GET', '/missing', 404];
        yield 'HEAD missing page' => ['HEAD', '/missing', 404];
        yield 'method not allowed' => ['POST', '/p/check', 405];
    }

    public function testMalformedAndRejectedChecksUseTheResponseNonce(): void
    {
        foreach ([[], ['id' => 'DEMO', 'term' => '03.11.2026', 'plate' => 'AB1234', 'sign' => 'bad']] as $query) {
            $response = $this->request(method: 'GET', path: '/p/check.php', query: $query);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame(1, preg_match(
                pattern: '/<style nonce="([^"]+)">/',
                subject: (string) $response->getBody(),
                matches: $m,
            ));
            self::assertStringContainsString("'nonce-{$m[1]}'", $response->getHeaderLine('Content-Security-Policy'));
        }
    }

    private function authorize(string $password = 'test-password'): string
    {
        $this->cookies = [];
        $form = $this->request(method: 'GET', path: '/admin/login');
        self::assertSame(
            1,
            preg_match(
                pattern: '/name="csrf" value="([a-f0-9]{64})"/',
                subject: (string) $form->getBody(),
                matches: $match,
            ),
        );
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/login',
                body: ['csrf' => $match[1], 'username' => 'admin', 'password' => $password],
            )->getStatusCode(),
        );
        $form = $this->request(method: 'GET', path: '/admin/permits/generate');
        self::assertSame(
            1,
            preg_match(
                pattern: '/name="csrf" value="([a-f0-9]{64})"/',
                subject: (string) $form->getBody(),
                matches: $match,
            ),
        );

        return $match[1];
    }

    public function testOldGeneratorUrlsRedirectAndRejectPosts(): void
    {
        foreach (['/permit', '/permit/', '/permit/index.php'] as $path) {
            $redirect = $this->request(method: 'GET', path: $path);
            self::assertSame(303, $redirect->getStatusCode());
            self::assertSame('/admin/permits/generate', $redirect->getHeaderLine('Location'));
            self::assertSame(
                405,
                $this->request(method: 'POST', path: $path, body: ['passwd' => 'test-password'])->getStatusCode(),
            );
        }
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
    }

    #[DataProvider('generationCases')]
    public function testGenerateAndVerifyExistingUrls(string $path, bool $guest): void
    {
        $data = [
            'csrf' => $this->authorize(),
            'id' => '26/1',
            'term' => '03.11.2026',
            'plate' => 'ab1234',
            'apt' => '48',
        ];
        if ($guest) {
            $data['guest'] = 'on';
        }
        $response = $this->request(method: 'POST', path: $path, body: $data);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('Atļauja nr. 26/1', $html);
        self::assertStringContainsString($guest ? 'VIESU' : 'AB1234', $html);
        self::assertStringContainsString('/permit/style.css', $html);
        self::assertSame(
            1,
            preg_match(
                pattern: '/class="qr" src="data:image\/svg\+xml;base64,([^"]+)"/',
                subject: $html,
                matches: $image,
            ),
        );
        $svg = base64_decode($image[1], strict: true);
        self::assertIsString($svg);
        self::assertNotEmpty($svg);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg));
        self::assertSame('http://www.w3.org/2000/svg', $document->documentElement?->namespaceURI);
        self::assertSame('crispEdges', $document->documentElement->getAttribute('shape-rendering'));
        self::assertSame(0, $document->getElementsByTagName('image')->length);
        self::assertSame(1, $document->getElementsByTagName('path')->length);
        self::assertSame(1, preg_match(pattern: '/alt="([^"]+)"/', subject: $html, matches: $match));
        $url = html_entity_decode(string: $match[1], flags: ENT_QUOTES);
        self::assertStringStartsWith('https://example.com/p/check?', $url);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expected = $guest ? 'cf9e8fb7ebadd332d85ab0947f540d485d6b4b6f97b78aaba42809bdf2d5896b'
            : 'fc6da0848440f98b814dd1e61447e3cd1dbe6ed85e154cdce70f2fca0531e1ac';
        self::assertSame('2', $query['v']);
        self::assertSame($expected, $query['sign']);
        foreach (['/p/check', '/p/check.php'] as $verifyPath) {
            $verified = $this->app->handle(new ServerRequestFactory()->createServerRequest(
                method: 'GET',
                uri: $verifyPath,
            )->withQueryParams($query));
            self::assertSame(200, $verified->getStatusCode());
            self::assertStringContainsString('Ir derīga', (string) $verified->getBody());
            self::assertStringContainsString($guest ? '48. dzīvokļa' : 'AB1234', (string) $verified->getBody());
        }
        foreach (
            [
                ['id' => 'changed'], ['term' => '04.11.2026'],
                [$guest ? 'apt' : 'plate' => 'CHANGED'], ['v' => '1'], ['v' => ''],
                ['guest' => $guest ? '' : '1'], ['sign' => str_repeat('0', 64)],
                [
                    'sign' => $guest
                        ? 'd05b2e5c1fd04bdc6ddb54ea8eac0a3b'
                        : self::LEGACY_RESIDENT_SIGNATURE,
                ],
            ] as $change
        ) {
            $verified = $this->app->handle(new ServerRequestFactory()->createServerRequest(
                method: 'GET',
                uri: '/p/check',
            )->withQueryParams(array_replace($query, $change)));
            self::assertSame(200, $verified->getStatusCode());
            self::assertStringContainsString(
                'Neizdevās iziet atļaujas drošības pārbaudi!',
                (string) $verified->getBody(),
            );
        }
        unset($query['v']);
        self::assertStringContainsString(
            'Neizdevās iziet atļaujas drošības pārbaudi!',
            (string) $this->app->handle(new ServerRequestFactory()->createServerRequest(
                method: 'GET',
                uri: '/p/check',
            )->withQueryParams($query))->getBody(),
        );
    }

    /** @return iterable<array{string, bool}> */
    public static function generationCases(): iterable
    {
        yield ['/admin/permits/generate', false];
        yield ['/admin/permits/generate', true];
    }

    #[DataProvider('manageablePermitIds')]
    public function testEveryNewPermitIdCanBeBlacklisted(string $id, bool $guest): void
    {
        $csrf = $this->authorize();
        $data = [
            'csrf' => $csrf, 'id' => $id, 'term' => '31.12.2099', 'plate' => 'ab1234',
            'apt' => '48',
        ];
        if ($guest) {
            $data['guest'] = 'on';
        }
        $response = $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame($id, $this->connection->fetchOne('SELECT permit_id FROM permits'));
        self::assertSame(
            1,
            preg_match(pattern: '/alt="([^"]+)"/', subject: (string) $response->getBody(), matches: $match),
        );
        $url = html_entity_decode(string: $match[1], flags: ENT_QUOTES);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $claims);
        $query = [];
        foreach ($claims as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            $query[$key] = $value;
        }
        self::assertSame($id, $query['id']);
        self::assertSame($guest, isset($query['guest']));
        self::assertStringContainsString(
            'Ir derīga',
            (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody(),
        );
        self::assertSame(
            303,
            $this->request(method: 'POST', path: '/admin/blacklist', body: [
                'csrf' => $csrf, 'action' => 'add', 'id' => $id,
            ])->getStatusCode(),
        );
        self::assertTrue(new BlacklistRepository($this->connection)->contains($id));
        self::assertStringContainsString(
            'Atļauja ir anulēta',
            (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody(),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function manageablePermitIds(): iterable
    {
        foreach ([false, true] as $guest) {
            $type = $guest ? 'guest' : 'resident';
            yield $type . ' zero' => ['0', $guest];
            yield $type . ' exact spaces' => [' 0 ', $guest];
            yield $type . ' Unicode and punctuation' => ['A&Ā/Квартира-1', $guest];
            yield $type . ' ASCII boundary' => [str_repeat('a', 255), $guest];
            yield $type . ' Unicode byte boundary' => [str_repeat('ā', 127) . 'a', $guest];
        }
    }

    #[DataProvider('legacyPermitIds')]
    public function testLegacyIdsStillVerifyButCannotBeNewlyIssued(string $id, bool $guest): void
    {
        /** @psalm-suppress WeakHash Generate an independent v1 fixture for the supplied legacy ID. */
        $signature = md5(($guest ? '48' : 'AB1234') . $id . '31.12.2099test-secret' . ($guest ? 'guest' : ''));
        $query = [
            'id' => $id, 'term' => '31.12.2099',
            'sign' => $signature,
        ];
        $query += $guest ? ['guest' => '1', 'apt' => '48'] : ['plate' => 'AB1234'];
        foreach (['/p/check', '/p/check.php'] as $path) {
            $response = $this->request(method: 'GET', path: $path, query: $query);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Ir derīga', (string) $response->getBody());
        }
        $data = [
            'csrf' => $this->authorize(), 'id' => $id, 'term' => '31.12.2099', 'plate' => 'ab1234',
            'apt' => '48',
        ];
        if ($guest) {
            $data['guest'] = 'on';
        }
        $response = $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter a permit ID', (string) $response->getBody());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function legacyPermitIds(): iterable
    {
        foreach ([false, true] as $guest) {
            $type = $guest ? 'guest' : 'resident';
            yield $type . ' whitespace' => ['   ', $guest];
            yield $type . ' long ASCII' => [str_repeat('a', 256), $guest];
            yield $type . ' long Unicode' => [str_repeat('ā', 128), $guest];
            yield $type . ' newline' => ["a\nb", $guest];
            yield $type . ' null byte' => ["a\0b", $guest];
            yield $type . ' DEL' => ["a\x7fb", $guest];
        }
    }

    public function testVerificationFailuresAndPrecedence(): void
    {
        foreach (
            [
                [
                    'id' => '26/1',
                    'term' => '03.11.2026',
                    'sign' => 'bad',
                    'reason' => 'Neizdevās iziet atļaujas drošības pārbaudi!',
                ],
                ['id' => '26/1', 'term' => '01.01.2020', 'sign' => 'bad', 'reason' => 'Atļaujas termiņš ir beidzies'],
                ['id' => 'DEMO-BLOCKED-1', 'term' => '01.01.2020', 'sign' => 'bad', 'reason' => 'Atļauja ir anulēta'],
                ['id' => 'DEMO-BLOCKED-1', 'term' => '31.02.2026', 'sign' => 'bad', 'reason' => 'Atļauja ir anulēta'],
                ['id' => '26/1', 'term' => '31.02.2026', 'sign' => 'bad', 'reason' => 'Nederīgs derīguma termiņš'],
            ] as $case
        ) {
            $response = $this->request(method: 'GET', path: '/p/check', query: $case);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Nav derīga', (string) $response->getBody());
            self::assertStringContainsString($case['reason'], (string) $response->getBody());
        }
        self::assertStringContainsString(
            'Nederīgs derīguma termiņš',
            (string) $this->request(method: 'GET', path: '/p/check')->getBody(),
        );
        $request = new ServerRequestFactory()->createServerRequest(
            method: 'GET',
            uri: '/p/check',
        )->withQueryParams(['id' => ['invalid']]);
        self::assertStringContainsString('Nederīgs pieprasījums', (string) $this->app->handle($request)->getBody());
    }

    public function testEachCheckStoresItsIdOutcomeAndSubmittedPermitDetails(): void
    {
        foreach (['/p/check', '/p/check.php'] as $path) {
            foreach (
                [
                    ['26/1', '03.11.2026', self::LEGACY_RESIDENT_SIGNATURE, 'valid', null],
                    ['DEMO-BLOCKED-1', '03.11.2026', 'private-signature', 'rejected', 'revoked'],
                    ['26/1', '01.01.2020', 'private-signature', 'rejected', 'expired'],
                    ['26/1', '03.11.2026', 'private-signature', 'rejected', 'signature'],
                ] as [$id, $term, $signature, $result, $reason]
            ) {
                $this->connection->executeStatement('DELETE FROM permit_checks');
                self::assertSame(
                    200,
                    $this->request(
                        method: 'GET',
                        path: $path,
                        query: [
                            'id' => $id,
                            'plate' => 'AB1234',
                            'term' => $term,
                            'sign' => $signature,
                            'apt' => 'private-apartment',
                        ],
                    )->getStatusCode(),
                );
                $rows = new PermitCheckRepository($this->connection)->search(
                    id: '',
                    result: null,
                    offset: 0,
                    limit: 25,
                );
                self::assertCount(1, $rows);
                $row = $rows[0];
                self::assertSame([
                    'permit_id' => $id, 'id_truncated' => false, 'method' => 'GET', 'path' => $path,
                    'result' => $result, 'reason' => $reason, 'status' => 200, 'checked_at' => '2026-11-03 10:00:00',
                ], [
                    'permit_id' => $row->permitId,
                    'id_truncated' => $row->idTruncated,
                    'method' => $row->method,
                    'path' => $row->path,
                    'result' => $row->outcome->value,
                    'reason' => $row->reason?->value,
                    'status' => $row->status,
                    'checked_at' => $row->checkedAt->format('Y-m-d H:i:s'),
                ]);
                self::assertSame([], $this->logs->getRecords());
                self::assertSame('private-apartment', $row->apartment);
                self::assertSame($term, $row->expiresOn?->format('d.m.Y'));
            }
        }
    }

    public function testMalformedAndOversizedIdsAreStoredSafely(): void
    {
        foreach ([['bad'], null, str_repeat('x', 300), str_repeat('ā', 140), "bad\xffID", "26/1\nnew-line"] as $id) {
            $this->connection->executeStatement('DELETE FROM permit_checks');
            $query = null === $id ? [] : ['id' => $id];
            $this->request(method: 'GET', path: '/p/check', query: $query);
            $rows = new PermitCheckRepository($this->connection)->search(id: '', result: null, offset: 0, limit: 25);
            self::assertCount(1, $rows);
            $clean = is_string($id) ? mb_scrub(string: $id, encoding: 'UTF-8') : null;
            self::assertSame(
                null === $clean ? null : mb_strcut(string: $clean, start: 0, length: 255, encoding: 'UTF-8'),
                $rows[0]->permitId,
            );
            self::assertSame(null !== $clean && strlen($clean) > 255, $rows[0]->idTruncated);
            self::assertSame(is_array($id) ? 'malformed' : 'rejected', $rows[0]->outcome->value);
            self::assertSame(is_array($id) ? 'request' : 'expiration', $rows[0]->reason?->value);
            self::assertTrue(mb_check_encoding(value: $rows[0]->permitId ?? '', encoding: 'UTF-8'));
        }
    }

    public function testAuditWriteFailureDoesNotRejectAValidPermit(): void
    {
        $this->connection->executeStatement('DROP TABLE permit_checks');
        $response = $this->request(method: 'GET', path: '/p/check', query: [
            'id' => '26/1',
            'plate' => 'AB1234',
            'term' => '03.11.2026',
            'sign' => self::LEGACY_RESIDENT_SIGNATURE,
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Ir derīga', (string) $response->getBody());
        self::assertTrue($this->logs->hasRecord('Unable to store permit check history', Level::Error));
        self::assertSame(['error_type', 'request_id'], array_keys($this->logs->getRecords()[0]->context));
        self::assertSame($response->getHeaderLine('X-Request-ID'), $this->logs->getRecords()[0]->context['request_id']);
    }

    public function testErrorsBeforeControllerCreationAreRecordedWhenTheDatabaseIsAvailable(): void
    {
        $this->connection->delete('app_settings', ['setting_key' => 'signing_secret']);
        $response = $this->request(method: 'GET', path: '/p/check', query: ['id' => '26/1']);
        self::assertSame(500, $response->getStatusCode());
        $rows = new PermitCheckRepository($this->connection)->search(id: '', result: null, offset: 0, limit: 25);
        self::assertCount(1, $rows);
        self::assertSame('26/1', $rows[0]->permitId);
        self::assertSame('error', $rows[0]->outcome->value);
        self::assertSame('internal_error', $rows[0]->reason?->value);
        self::assertSame(500, $rows[0]->status);
    }

    public function testPublicCopyAndPermitStylesAreConfigurableWithoutChangingQrSignatures(): void
    {
        $container = $this->app->getContainer();
        $translator = $container->get(TranslatorInterface::class);
        self::assertInstanceOf(Translator::class, $translator);
        $translator->addResource(
            format: 'array',
            resource: ['check.title' => 'Piemēra pārbaude', 'reason.revoked' => 'Atļauja ir bloķēta'],
            locale: 'lv',
            domain: 'public',
        );
        $container->set(PublicUi::class, new PublicUi($translator, [
            '%location%' => 'Piemēra teritorijā', '%check_location%' => 'Piemēra teritorijā',
            '%guest_location%' => 'Piemēra viesu stāvvietā', '%basis%' => 'piemēra lēmumu',
        ]));
        $twig = $container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $twig->setLoader(new ChainLoader([
            new ArrayLoader(['permit.css.twig' => '.wrapper { border-color: blue; }']),
            $twig->getLoader(),
        ]));
        $data = ['csrf' => $this->authorize(), 'id' => 'custom/1', 'term' => '03.11.2026', 'plate' => 'AB1234'];
        $html = (string) $this->request(method: 'POST', path: '/admin/permits/generate', body: $data)->getBody();
        foreach (['Piemēra teritorijā', 'piemēra lēmumu', 'border-color: blue', '/permit/style.css'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertSame(1, preg_match(pattern: '/alt="([^"]+)"/', subject: $html, matches: $match));
        $url = html_entity_decode(string: $match[1], flags: ENT_QUOTES);
        self::assertStringContainsString('27e527b0ae5a9224b2d60ce269316a4329a4de0385f72b5c4d09deabe709f850', $url);
        $query = [
            'id' => 'custom/1',
            'plate' => 'AB1234',
            'term' => '03.11.2026',
            'sign' => '2e2c10779adf86face7c32c1984f4a35',
            'lang' => 'ru',
        ];
        $body = (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody();
        self::assertStringContainsString('<html lang="lv">', $body);
        self::assertStringContainsString('Piemēra pārbaude', $body);
        self::assertStringContainsString('Piemēra teritorijā', $body);
        new BlacklistRepository($this->connection)->add('custom/1');
        self::assertStringContainsString(
            'Atļauja ir bloķēta',
            (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody(),
        );
        $data['guest'] = 'on';
        $data['apt'] = '48';
        self::assertStringContainsString(
            'Piemēra viesu stāvvietā',
            (string) $this->request(method: 'POST', path: '/admin/permits/generate', body: $data)->getBody(),
        );
    }

    public function testOutputAndQrQueryAreEscaped(): void
    {
        $id = '<script>alert(1)</script>&id=other';
        $response = $this->request(method: 'POST', path: '/admin/permits/generate', body: [
            'csrf' => $this->authorize(), 'id' => $id, 'term' => '03.11.2026', 'plate' => 'AB&12',
        ]);
        $html = (string) $response->getBody();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('AB&amp;12', $html);
        self::assertStringContainsString(rawurlencode($id), $html);
    }

    public function testFormBodyParsingAndInvalidGeneration(): void
    {
        $csrf = $this->authorize();
        $request = new ServerRequestFactory()->createServerRequest(method: 'POST', uri: '/admin/permits/generate')
            ->withCookieParams($this->cookies)->withHeader(
                name: 'Content-Type',
                value: 'application/x-www-form-urlencoded',
            );
        $request->getBody()->write(http_build_query([
            'csrf' => $csrf, 'id' => '26/1', 'term' => '31.02.2026', 'plate' => 'AB1234',
        ]));
        self::assertStringContainsString('Invalid expiration date.', (string) $this->app->handle($request)->getBody());
        self::assertStringContainsString(
            'Enter a permit ID',
            (string) $this->request(
                method: 'POST',
                path: '/admin/permits/generate',
                body: ['csrf' => $this->authorize()],
            )->getBody(),
        );
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $missing = $this->request(method: 'GET', path: '/missing');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringNotContainsString(\Slim\Exception\HttpNotFoundException::class, (string) $missing->getBody());
        self::assertStringContainsString('404 Not Found', (string) $missing->getBody());
        self::assertTrue($this->logs->hasRecords(Level::Notice));
        $notAllowed = $this->request(method: 'POST', path: '/health');
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame('GET', $notAllowed->getHeaderLine('Allow'));
        self::assertSame('', (string) $this->request(method: 'HEAD', path: '/missing')->getBody());
    }

    #[DataProvider('internalErrors')]
    public function testInternalErrorsStayPrivateAndAreLogged(\Throwable $exception): void
    {
        $this->app->get('/__failure', function () use ($exception): ResponseInterface {
            throw $exception;
        });
        $request = new ServerRequestFactory()->createServerRequest(
            method: 'GET',
            uri: '/__failure?sign=private-signature',
        )
            ->withHeader(name: 'Accept', value: 'application/json')
            ->withHeader(name: 'Authorization', value: 'Bearer private-token')
            ->withParsedBody(['passwd' => 'private-password']);
        $response = $this->app->handle($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('500 Internal Server Error', $body);
        self::assertStringNotContainsString('private-diagnostic', $body);
        self::assertStringNotContainsString($exception::class, $body);
        self::assertStringNotContainsString(__DIR__, $body);
        $record = $this->logs->getRecords()[0] ?? self::fail('Expected an error log');
        self::assertSame(Level::Error, $record->level);
        self::assertSame([
            'method' => 'GET', 'path' => '/__failure', 'status' => 500, 'exception' => $exception,
            'request_id' => $response->getHeaderLine('X-Request-ID'),
        ], $record->context);
    }

    /** @return iterable<array{\Throwable}> */
    public static function internalErrors(): iterable
    {
        // A non-HTTP exception's numeric code must not become a public status.
        yield [new \RuntimeException('private-diagnostic', 404)];
        yield [new \TypeError('private-diagnostic')];
    }

    public function testPsrClockOverrideControlsPermitExpiration(): void
    {
        $this->app->getContainer()->set(
            ClockInterface::class,
            new FrozenClock(new \DateTimeImmutable('2026-11-04T00:00:00+02:00')),
        );
        $response = $this->request(method: 'GET', path: '/p/check', query: [
            'id' => '26/1', 'term' => '03.11.2026', 'plate' => 'AB1234',
            'sign' => self::LEGACY_RESIDENT_SIGNATURE,
        ]);
        self::assertStringContainsString('Atļaujas termiņš ir beidzies', (string) $response->getBody());
    }

    public function testGenerationIsStoredAndReprintingIsIdempotent(): void
    {
        $data = ['csrf' => $this->authorize(), 'id' => 'new/1', 'term' => '03.11.2026', 'plate' => 'ab1234'];
        $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $row = $this->connection->fetchAssociative('SELECT * FROM permits');
        self::assertNotFalse($row);
        self::assertSame('new/1', $row['permit_id']);
        self::assertSame('AB1234', $row['plate']);
        self::assertSame('2026-11-03', $row['expires_on']);
        self::assertSame('2026-11-03 10:00:00', $row['created_at']);
        $data['guest'] = 'on';
        $data['apt'] = '48';
        $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $data['term'] = '04.11.2026';
        $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(3, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $tampered = $this->request(
            method: 'GET',
            path: '/p/check',
            query: ['id' => 'new/1', 'term' => '03.11.2026', 'plate' => 'AB1234', 'sign' => 'bad'],
        );
        self::assertStringContainsString('Neizdevās iziet atļaujas drošības pārbaudi!', (string) $tampered->getBody());
    }

    public function testPreviouslyIssuedUnrecordedPermitRemainsValidAndCanBeRevoked(): void
    {
        $query = [
            'id' => '26/1',
            'term' => '03.11.2026',
            'plate' => 'AB1234',
            'sign' => self::LEGACY_RESIDENT_SIGNATURE,
        ];
        self::assertStringContainsString(
            'Ir derīga',
            (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody(),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $blacklist = new BlacklistRepository($this->connection);
        $blacklist->add(id: '26/1', reason: 'Revoked');
        self::assertStringContainsString(
            'Atļauja ir anulēta',
            (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody(),
        );
        $blacklist->remove('26/1');
        self::assertStringContainsString(
            'Ir derīga',
            (string) $this->request(method: 'GET', path: '/p/check', query: $query)->getBody(),
        );
    }

    public function testInvalidOrUnauthenticatedGenerationStoresNothing(): void
    {
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/permits/generate',
                body: ['passwd' => 'test-password'],
            )->getStatusCode(),
        );
        $this->request(
            method: 'POST',
            path: '/admin/permits/generate',
            body: ['csrf' => $this->authorize(), 'id' => 'bad', 'plate' => 'TEST', 'term' => '31.02.2026'],
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
    }

    public function testDatabaseFailureNeverApprovesAPermitOrExposesDetails(): void
    {
        $this->app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$this->logs]),
            connection: DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );
        $response = $this->request(method: 'GET', path: '/p/check', query: ['id' => '26/1', 'term' => '31.12.2099']);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('app_settings', (string) $response->getBody());
        self::assertTrue($this->logs->hasRecords(Level::Error));
        self::assertTrue($this->logs->hasRecord('Unable to store permit check history', Level::Error));
        self::assertSame(200, $this->request(method: 'GET', path: '/health')->getStatusCode());
    }

    public function testDatabaseSettingsControlTheGeneratorOnANewRequest(): void
    {
        $this->connection->update(
            table: 'app_settings',
            data: ['setting_value' => 'https://new.example.com'],
            criteria: ['setting_key' => 'base_url'],
        );
        $this->connection->update(
            table: 'app_settings',
            data: ['setting_value' => password_hash(
                password: 'changed-password',
                algo: PASSWORD_BCRYPT,
                options: ['cost' => 4],
            )],
            criteria: ['setting_key' => 'password_hash'],
        );
        $this->app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$this->logs]),
            connection: $this->connection,
        );
        $response = $this->request(
            method: 'POST',
            path: '/admin/permits/generate',
            body: [
                'csrf' => $this->authorize('changed-password'),
                'id' => 'new',
                'plate' => 'TEST',
                'term' => '31.12.2099',
            ],
        );
        self::assertStringContainsString('https://new.example.com/p/check?', (string) $response->getBody());
    }
}
