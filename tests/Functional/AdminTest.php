<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Permit\Model\Permit;
use App\Http\Session\AdminNoticeCookie;
use App\Http\Session\AdminSessionCookie;
use App\Http\Translation\AdminLanguage;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\PermitRepository;
use Doctrine\DBAL\Connection;
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
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;
use Tests\Support\Database;
use Tests\Support\FrozenClock;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class AdminTest extends TestCase
{
    private Connection $connection;
    private TestHandler $logs;
    private \DateTimeImmutable $now;
    /** @var array<string, string> */
    private array $cookies = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = Database::create();
        $this->logs = new TestHandler();
        $this->now = new \DateTimeImmutable('2026-11-03T12:00:00+02:00');
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null, bool $secure = true): ResponseInterface
    {
        $app = ApplicationFactory::create(
            logger: new Logger(name: 'test', handlers: [$this->logs]),
            connection: $this->connection,
        );
        $app->getContainer()->set(ClockInterface::class, new FrozenClock($this->now));
        $query = [];
        $queryString = parse_url($path, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }
        $request = new ServerRequestFactory()->createServerRequest(
            method: $method,
            uri: ($secure ? 'https' : 'http') . '://localhost' . $path,
            serverParams: ['REMOTE_ADDR' => '192.0.2.1'],
        )
            ->withCookieParams($this->cookies)->withQueryParams($query);
        if (null !== $body) {
            $request = $request->withParsedBody($body);
        }
        $response = $app->handle($request);
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $parts = explode(separator: ';', string: $header);
            $cookie = explode(separator: '=', string: $parts[0], limit: 2);
            $name = $cookie[0];
            $value = $cookie[1] ?? self::fail('The session cookie has no value.');
            if (str_contains(haystack: $header, needle: 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    private function csrf(ResponseInterface $response): string
    {
        self::assertSame(
            1,
            preg_match(
                pattern: '/name="csrf" value="([a-f0-9]{64})"/',
                subject: (string) $response->getBody(),
                matches: $match,
            ),
        );

        return $match[1];
    }

    private function login(): ResponseInterface
    {
        $csrf = $this->csrf($this->request(method: 'GET', path: '/admin/login'));
        $response = $this->request(
            method: 'POST',
            path: '/admin/login',
            body: ['csrf' => $csrf, 'username' => 'admin', 'password' => 'test-password'],
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/permits', $response->getHeaderLine('Location'));

        return $response;
    }

    private function permit(
        string $id,
        string $plate = 'AB1234',
        bool $guest = false,
        string $apartment = '',
        string $term = '03.11.2026',
    ): void {
        $expiration = \DateTimeImmutable::createFromFormat(
            format: '!d.m.Y',
            datetime: $term,
            timezone: new \DateTimeZone('Europe/Riga'),
        );
        self::assertNotFalse($expiration);
        new PermitRepository($this->connection)->save(
            new Permit(id: $id, term: $term, plate: $plate, guest: $guest, apartment: $apartment),
            $expiration,
            $this->now,
        );
    }

    #[DataProvider('languages')]
    public function testLanguageSelectionPersistsAcrossAdminScreens(
        string $locale,
        string $signIn,
        string $permits,
        string $generate,
        string $valid,
        string $loginError,
        string $dateError,
    ): void {
        $login = $this->request(method: 'GET', path: '/admin/login?lang=' . $locale);
        self::assertSame($locale, $login->getHeaderLine('Content-Language'));
        self::assertSame($locale, $this->cookies[AdminLanguage::COOKIE]);
        $body = (string) $login->getBody();
        self::assertStringContainsString('<html lang="' . $locale . '">', $body);
        self::assertStringContainsString($signIn, $body);
        self::assertStringNotContainsString('permit', strtolower($body));
        self::assertStringNotContainsString('parking', strtolower($body));
        $wrong = $this->request(
            method: 'POST',
            path: '/admin/login',
            body: ['csrf' => $this->csrf($login), 'username' => 'admin', 'password' => 'wrong'],
        );
        self::assertStringContainsString($loginError, (string) $wrong->getBody());
        $this->login();
        $this->permit('translated/1');
        $list = $this->request(method: 'GET', path: '/admin/permits');
        self::assertSame($locale, $list->getHeaderLine('Content-Language'));
        foreach ([$permits, $generate, $valid] as $text) {
            self::assertStringContainsString($text, (string) $list->getBody());
        }
        self::assertStringNotContainsString('%page%', (string) $list->getBody());
        $form = $this->request(method: 'GET', path: '/admin/permits/generate');
        self::assertStringContainsString($generate, (string) $form->getBody());
        $csrf = $this->csrf($form);
        $invalid = $this->request(
            method: 'POST',
            path: '/admin/permits/generate',
            body: ['csrf' => $csrf, 'id' => 'test', 'plate' => 'TEST', 'term' => '31.02.2026'],
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString($dateError, (string) $invalid->getBody());
        $print = $this->request(
            method: 'POST',
            path: '/admin/permits/generate',
            body: ['csrf' => $csrf, 'id' => 'print', 'plate' => 'TEST', 'term' => '03.11.2026'],
        );
        self::assertStringContainsString($generate, (string) $print->getBody());
        self::assertStringContainsString('Atļauja nr. print', (string) $print->getBody());
        self::assertStringContainsString('Derīga līdz:', (string) $print->getBody());
        $this->request(method: 'POST', path: '/admin/logout', body: ['csrf' => $csrf]);
        self::assertSame($locale, $this->cookies[AdminLanguage::COOKIE]);
        self::assertStringContainsString(
            $signIn,
            (string) $this->request(method: 'GET', path: '/admin/login')->getBody(),
        );
    }

    /** @return iterable<string, array{string, string, string, string, string, string, string}> */
    public static function languages(): iterable
    {
        yield 'English' => [
            'en',
            'Sign in',
            'Permits',
            'Generate a permit',
            'Valid',
            'Invalid login or password.',
            'Invalid expiration date.',
        ];
        yield 'Latvian' => [
            'lv',
            'Pieteikties',
            'Atļaujas',
            'Ģenerēt atļauju',
            'Derīga',
            'Nepareizs lietotājvārds vai parole.',
            'Nederīgs derīguma termiņš.',
        ];
        yield 'Russian' => [
            'ru',
            'Войти',
            'Разрешения',
            'Создать разрешение',
            'Действительно',
            'Неверное имя пользователя или пароль.',
            'Неверная дата окончания действия.',
        ];
    }

    public function testInvalidLanguagesFallbackAndLocalizedErrorsStayGeneric(): void
    {
        self::assertSame(
            'en',
            $this->request(method: 'GET', path: '/admin/login?lang[]=ru')->getHeaderLine('Content-Language'),
        );
        self::assertSame(
            'en',
            $this->request(method: 'GET', path: '/admin/login?lang=../../private')->getHeaderLine('Content-Language'),
        );
        $this->request(method: 'GET', path: '/admin/login?lang=ru');
        self::assertSame(
            'ru',
            $this->request(method: 'GET', path: '/admin/login?lang=unsupported')->getHeaderLine('Content-Language'),
        );
        $this->login();
        $links = (string) $this->request(method: 'GET', path: '/admin/permits?q=TEST&page=2')->getBody();
        self::assertStringContainsString('/admin/permits?q=TEST&amp;page=2&amp;lang=lv', $links);
        $forbidden = $this->request(method: 'POST', path: '/admin/permits/generate');
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertStringContainsString('Доступ запрещён', (string) $forbidden->getBody());
        self::assertStringNotContainsString('permit', (string) $forbidden->getBody());
        self::assertSame('ru', $forbidden->getHeaderLine('Content-Language'));
        self::assertStringContainsString(
            'Страница не найдена',
            (string) $this->request(method: 'GET', path: '/admin/missing')->getBody(),
        );
        $query = http_build_query([
            'id' => 'legacy',
            'term' => '03.11.2026',
            'plate' => 'TEST',
            'sign' => 'eab3b8e2233ef88c3041435c104ba8da',
            'lang' => 'ru',
        ]);
        foreach (['/p/check', '/p/check.php'] as $path) {
            $public = (string) $this->request(method: 'GET', path: $path . '?' . $query)->getBody();
            self::assertStringContainsString('<html lang="lv">', $public);
            self::assertStringContainsString('Ir derīga', $public);
            self::assertStringNotContainsString('Действительно', $public);
            $invalid = (string) $this->request(method: 'GET', path: $path . '?lang=ru')->getBody();
            self::assertStringContainsString('Nav derīga', $invalid);
            self::assertStringContainsString('Nederīgs derīguma termiņš', $invalid);
        }
    }

    public function testBlacklistChangesImmediatelyRevokeAndRestoreLegacyPermits(): void
    {
        $query = http_build_query([
            'id' => '26/48',
            'term' => '03.11.2026',
            'plate' => 'TEST',
            'sign' => '03be660cb4421b1d4fb5fa41acb37c60',
        ]);
        $url = '/p/check?' . $query;
        self::assertStringContainsString('Ir derīga', (string) $this->request(method: 'GET', path: $url)->getBody());
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['action' => 'add', 'id' => '26/48'],
            )->getStatusCode(),
        );
        $this->login();
        $form = $this->request(method: 'GET', path: '/admin/blacklist');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('DEMO-BLOCKED-1', (string) $form->getBody());
        $csrf = $this->csrf($form);
        self::assertSame(
            403,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['action' => 'add', 'id' => '26/48'],
            )->getStatusCode(),
        );
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['csrf' => $csrf, 'action' => 'add', 'id' => '26/48', 'reason' => 'Revoked by admin'],
            )->getStatusCode(),
        );
        self::assertStringContainsString(
            'Atļauja ir anulēta',
            (string) $this->request(method: 'GET', path: $url)->getBody(),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame(
            409,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['csrf' => $csrf, 'action' => 'add', 'id' => '26/48', 'reason' => 'Overwrite'],
            )->getStatusCode(),
        );
        self::assertSame(
            'Revoked by admin',
            $this->connection->fetchOne(
                'SELECT reason FROM blacklisted_ids WHERE id_hash = ?',
                [hash(algo: 'sha256', data: '26/48')],
            ),
        );
        $this->permit('26/48');
        self::assertStringContainsString(
            'Revoked',
            (string) $this->request(method: 'GET', path: '/admin/permits?q=26%2F48')->getBody(),
        );
        self::assertSame(
            403,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['action' => 'remove', 'id' => '26/48'],
            )->getStatusCode(),
        );
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['csrf' => $csrf, 'action' => 'remove', 'id' => '26/48'],
            )->getStatusCode(),
        );
        self::assertStringContainsString('Ir derīga', (string) $this->request(method: 'GET', path: $url)->getBody());
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['csrf' => $csrf, 'action' => 'add', 'id' => '48'],
            )->getStatusCode(),
        );
        self::assertStringContainsString('Ir derīga', (string) $this->request(method: 'GET', path: $url)->getBody());
        $record = array_find(
            $this->logs->getRecords(),
            static fn($record): bool => 'Admin blacklist changed' === $record->message,
        );
        self::assertNotNull($record);
        self::assertIsString($record->context['request_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $record->context['request_id']);
        self::assertSame([
            'action' => 'add', 'id_hash' => hash(algo: 'sha256', data: '26/48'),
            'request_id' => $record->context['request_id'],
        ], $record->context);
        $this->request(method: 'POST', path: '/admin/logout', body: ['csrf' => $csrf]);
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['csrf' => $csrf, 'action' => 'remove', 'id' => '48'],
            )->getStatusCode(),
        );
        self::assertTrue(new BlacklistRepository($this->connection)->contains('48'));
    }

    public function testBlacklistSuccessFeedbackAppearsOnceAndOnlyAfterSuccessfulChanges(): void
    {
        $this->login();
        $form = $this->request(method: 'GET', path: '/admin/blacklist');
        $csrf = $this->csrf($form);
        $data = ['csrf' => $csrf, 'action' => 'add', 'id' => 'DEMO-FEEDBACK'];
        self::assertSame(303, $this->request(method: 'POST', path: '/admin/blacklist', body: $data)->getStatusCode());
        self::assertArrayHasKey(AdminNoticeCookie::COOKIE, $this->cookies);
        $result = $this->request(method: 'GET', path: '/admin/blacklist');
        self::assertStringContainsString('The ID has been added to the blacklist.', (string) $result->getBody());
        self::assertArrayNotHasKey(AdminNoticeCookie::COOKIE, $this->cookies);
        self::assertStringNotContainsString(
            'id="action-notice"',
            (string) $this->request(method: 'GET', path: '/admin/blacklist')->getBody(),
        );
        $duplicate = $this->request(method: 'POST', path: '/admin/blacklist', body: $data);
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertArrayNotHasKey(AdminNoticeCookie::COOKIE, $this->cookies);
        self::assertStringNotContainsString('id="action-notice"', (string) $duplicate->getBody());
        $data['action'] = 'remove';
        self::assertSame(303, $this->request(method: 'POST', path: '/admin/blacklist', body: $data)->getStatusCode());
        self::assertStringContainsString(
            'The ID is no longer blacklisted.',
            (string) $this->request(method: 'GET', path: '/admin/blacklist')->getBody(),
        );
        $this->cookies[AdminNoticeCookie::COOKIE] = '<script>untrusted-notice</script>';
        $invalid = $this->request(method: 'GET', path: '/admin/blacklist');
        self::assertStringNotContainsString('untrusted-notice', (string) $invalid->getBody());
        self::assertArrayNotHasKey(AdminNoticeCookie::COOKIE, $this->cookies);
    }

    public function testBlacklistInputSearchPaginationAndEscaping(): void
    {
        $this->login();
        $csrf = $this->csrf($this->request(method: 'GET', path: '/admin/blacklist'));
        foreach (
            [
                ['id' => ''],
                ['id' => ' '],
                ['id' => "bad\nID"],
                ['id' => str_repeat('a', 256)],
                ['id' => 'new', 'reason' => str_repeat('x', 2001)],
            ] as $data
        ) {
            self::assertSame(
                422,
                $this->request(
                    method: 'POST',
                    path: '/admin/blacklist',
                    body: $data + ['action' => 'add', 'csrf' => $csrf],
                )->getStatusCode(),
            );
        }
        foreach ([['id' => []], ['id' => 'new', 'reason' => []], ['id' => 'new', 'action' => 'unknown']] as $data) {
            self::assertSame(
                400,
                $this->request(
                    method: 'POST',
                    path: '/admin/blacklist',
                    body: $data + ['action' => 'add', 'csrf' => $csrf],
                )->getStatusCode(),
            );
        }
        $id = '<script>bad</script>%';
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/blacklist',
                body: ['csrf' => $csrf, 'action' => 'add', 'id' => $id, 'reason' => '<b>Reason</b>'],
            )->getStatusCode(),
        );
        $body = (string) $this->request(method: 'GET', path: '/admin/blacklist?q=%25')->getBody();
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;%', $body);
        self::assertStringContainsString('&lt;b&gt;Reason&lt;/b&gt;', $body);
        self::assertStringNotContainsString('<script>bad</script>', $body);
        self::assertSame(1, substr_count(haystack: $body, needle: 'scope="row"'));
        self::assertSame(400, $this->request(method: 'GET', path: '/admin/blacklist?page=0')->getStatusCode());
        self::assertSame(400, $this->request(method: 'GET', path: '/admin/blacklist?q[]=bad')->getStatusCode());
        self::assertSame(405, $this->request(method: 'DELETE', path: '/admin/blacklist')->getStatusCode());
        for ($number = 1; $number <= 26; ++$number) {
            new BlacklistRepository($this->connection)->add('page-' . $number);
        }
        self::assertSame(
            25,
            substr_count(
                haystack: (string) $this->request(method: 'GET', path: '/admin/blacklist?q=page-')->getBody(),
                needle: 'scope="row"',
            ),
        );
        self::assertSame(
            1,
            substr_count(
                haystack: (string) $this->request(method: 'GET', path: '/admin/blacklist?q=page-&page=2')->getBody(),
                needle: 'scope="row"',
            ),
        );
        self::assertStringContainsString(
            'Чёрный список',
            (string) $this->request(method: 'GET', path: '/admin/blacklist?lang=ru')->getBody(),
        );
        self::assertStringContainsString(
            'Melnais saraksts',
            (string) $this->request(method: 'GET', path: '/admin/blacklist?lang=lv')->getBody(),
        );
    }

    public function testPermitCheckHistoryIsPrivateSearchableTranslatedAndReadOnly(): void
    {
        $this->request(
            method: 'GET',
            path: '/p/check?id=history-active&plate=TEST&term=03.11.2026&sign='
                . '387ba450a93cabda8e918b2bd0d4265f',
        );
        $this->request(method: 'GET', path: '/p/check.php?id=DEMO-BLOCKED-1');
        $this->request(method: 'GET', path: '/p/check?id[]=bad');
        $this->request(method: 'GET', path: '/p/check?id=' . rawurlencode('<script>bad</script>%'));
        $this->request(method: 'GET', path: '/p/check?id=' . str_repeat('x', 300));
        $this->login();
        $body = (string) $this->request(method: 'GET', path: '/admin/checks')->getBody();
        foreach (
            [
                'Permit checks',
                'history-active',
                'ID was blacklisted when checked',
                'Malformed request',
                'ID shortened',
                '03.11.2026 12:00:00',
            ] as $text
        ) {
            self::assertStringContainsString($text, $body);
        }
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;%', $body);
        self::assertStringNotContainsString('<script>bad</script>', $body);
        self::assertStringNotContainsString('test-secret', $body);
        self::assertSame(7, substr_count(haystack: $body, needle: 'scope="col"'));
        self::assertStringNotContainsString('/p/check', $body);
        self::assertStringNotContainsString('HTTP', $body);
        self::assertStringNotContainsString('<th scope="col">Request</th>', $body);
        self::assertStringContainsString(
            'href="/admin/checks"',
            (string) $this->request(method: 'GET', path: '/admin/permits')->getBody(),
        );
        $filtered = (string) $this->request(method: 'GET', path: '/admin/checks?result=valid&q=history')->getBody();
        self::assertStringContainsString('history-active', $filtered);
        self::assertStringNotContainsString('DEMO-BLOCKED-1', $filtered);
        self::assertSame(1, substr_count(haystack: $filtered, needle: 'scope="row"'));
        $empty = (string) $this->request(method: 'GET', path: '/admin/checks?q=missing')->getBody();
        self::assertStringContainsString('colspan="7"', $empty);
        self::assertStringContainsString('No matching permit checks.', $empty);
        self::assertSame(
            1,
            substr_count(
                haystack: (string) $this->request(method: 'GET', path: '/admin/checks?q=%25')->getBody(),
                needle: 'scope="row"',
            ),
        );
        self::assertStringContainsString(
            'Проверки разрешений',
            (string) $this->request(method: 'GET', path: '/admin/checks?lang=ru')->getBody(),
        );
        self::assertStringContainsString(
            'Atļauju pārbaudes',
            (string) $this->request(method: 'GET', path: '/admin/checks?lang=lv')->getBody(),
        );
        foreach (['?result=unknown', '?result[]=valid', '?page=0', '?q[]=bad'] as $query) {
            self::assertSame(400, $this->request(method: 'GET', path: '/admin/checks' . $query)->getStatusCode());
        }
        self::assertSame(405, $this->request(method: 'POST', path: '/admin/checks')->getStatusCode());
        self::assertSame(5, $this->connection->fetchOne('SELECT COUNT(*) FROM permit_checks'));
    }

    public function testPermitCheckHistoryPaginationPreservesFiltersAndOrdersNewestFirst(): void
    {
        for ($number = 1; $number <= 26; ++$number) {
            $this->request(method: 'GET', path: '/p/check?id=history-' . $number);
        }
        $this->login();
        $first = (string) $this->request(method: 'GET', path: '/admin/checks?q=history-&result=rejected')->getBody();
        self::assertSame(25, substr_count(haystack: $first, needle: 'scope="row"'));
        self::assertStringContainsString('history-26', $first);
        self::assertSame(0, preg_match(pattern: '~scope="row"[^>]*>\s*history-1\s*<~', subject: $first));
        self::assertStringContainsString('q=history-&amp;result=rejected&amp;page=2', $first);
        $second = (string) $this->request(
            method: 'GET',
            path: '/admin/checks?q=history-&result=rejected&page=2',
        )->getBody();
        self::assertSame(1, substr_count(haystack: $second, needle: 'scope="row"'));
        self::assertStringContainsString('history-1', $second);
        self::assertStringNotContainsString('history-26', $second);
    }

    public function testCheckHistoryCanBlacklistAnIdWithCsrfAuditFeedbackAndPreservedFilters(): void
    {
        $query = http_build_query([
            'id' => 'history-block', 'plate' => 'AB1234', 'apt' => '12', 'term' => '31.12.2099',
            'sign' => '3e282787cc00ed590c47a7595d301cf8',
        ]);
        $this->request(method: 'GET', path: '/p/check?' . $query);
        $this->login();
        $page = $this->request(method: 'GET', path: '/admin/checks');
        $body = (string) $page->getBody();
        self::assertStringContainsString('31.12.2099', $body);
        self::assertStringContainsString('<td class="text-break">12</td>', $body);
        self::assertStringContainsString('Every permit using this ID will be blocked.', $body);
        self::assertSame(403, $this->request(method: 'POST', path: '/admin/checks/blacklist')->getStatusCode());
        $csrf = $this->csrf($page);
        foreach ([[], ['id' => []]] as $data) {
            self::assertSame(400, $this->request(
                method: 'POST',
                path: '/admin/checks/blacklist',
                body: $data + ['csrf' => $csrf],
            )->getStatusCode());
        }
        self::assertSame(422, $this->request(
            method: 'POST',
            path: '/admin/checks/blacklist',
            body: ['csrf' => $csrf, 'id' => "bad\nID"],
        )->getStatusCode());
        $url = '/admin/checks/blacklist?q=history&result=valid&page=1';
        $response = $this->request(method: 'POST', path: $url, body: ['csrf' => $csrf, 'id' => 'history-block']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/checks?q=history&result=valid&page=1', $response->getHeaderLine('Location'));
        $body = (string) $this->request(method: 'GET', path: $response->getHeaderLine('Location'))->getBody();
        self::assertStringContainsString('The ID has been added to the blacklist.', $body);
        self::assertStringContainsString('name="action" value="remove"', $body);
        self::assertStringNotContainsString('name="action" value="add"', $body);
        self::assertTrue(new BlacklistRepository($this->connection)->contains('history-block'));
        self::assertSame('valid', $this->connection->fetchOne('SELECT result FROM permit_checks'));
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM admin_audit WHERE action = ?',
            ['blacklist_added'],
        ));
        self::assertSame(409, $this->request(
            method: 'POST',
            path: $url,
            body: ['csrf' => $csrf, 'id' => 'history-block'],
        )->getStatusCode());
        self::assertSame(405, $this->request(method: 'GET', path: '/admin/checks/blacklist')->getStatusCode());
        self::assertStringContainsString(
            'Atļauja ir anulēta',
            (string) $this->request(method: 'GET', path: '/p/check?' . $query)->getBody(),
        );
        $response = $this->request(
            method: 'POST',
            path: $url,
            body: ['csrf' => $csrf, 'id' => 'history-block', 'action' => 'remove'],
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertFalse(new BlacklistRepository($this->connection)->contains('history-block'));
        $body = (string) $this->request(method: 'GET', path: $response->getHeaderLine('Location'))->getBody();
        self::assertStringContainsString('The ID is no longer blacklisted.', $body);
        self::assertStringContainsString('name="action" value="add"', $body);
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM admin_audit WHERE action = ?',
            ['blacklist_removed'],
        ));
        self::assertSame(400, $this->request(
            method: 'POST',
            path: $url,
            body: ['csrf' => $csrf, 'id' => 'history-block', 'action' => 'invalid'],
        )->getStatusCode());
    }

    #[DataProvider('privateRequests')]
    public function testPrivatePagesRequireLogin(string $method, string $path): void
    {
        $this->permit('PRIVATE-ID');
        $response = $this->request(method: $method, path: $path);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('PRIVATE-ID', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function privateRequests(): iterable
    {
        foreach (
            [
                '/admin',
                '/admin/',
                '/admin/permits',
                '/admin/permits?q=PRIVATE',
                '/admin/permits/generate',
                '/admin/blacklist',
                '/admin/checks',
            ] as $path
        ) {
            yield 'GET ' . $path => ['GET', $path];
            yield 'HEAD ' . $path => ['HEAD', $path];
        }
        foreach (
            [
                '/admin/permits/generate',
                '/admin/blacklist',
                '/admin/permits/' . str_repeat('a', 64) . '/reprint',
                '/admin/checks/blacklist',
                '/admin/logout',
            ] as $path
        ) {
            yield 'POST ' . $path => ['POST', $path];
        }
    }

    public function testGenerationRequiresSessionAndCsrfAndHasSharedNavigation(): void
    {
        $data = ['id' => 'new/1', 'plate' => 'ab1234', 'term' => '03.11.2026'];
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/permits/generate',
                body: $data + ['passwd' => 'test-password'],
            )->getStatusCode(),
        );
        $this->login();
        $form = $this->request(method: 'GET', path: '/admin/permits/generate');
        self::assertSame(200, $form->getStatusCode());
        $html = (string) $form->getBody();
        self::assertStringNotContainsString('type="password"', $html);
        self::assertStringNotContainsString('name="passwd"', $html);
        foreach (['/admin/permits', '/admin/permits/generate', '/admin/blacklist', '/admin/checks'] as $path) {
            self::assertStringContainsString('href="' . $path . '"', $html);
            self::assertStringContainsString(
                'href="' . $path . '"',
                (string) $this->request(method: 'GET', path: '/admin/permits')->getBody(),
            );
        }
        self::assertSame(
            403,
            $this->request(method: 'POST', path: '/admin/permits/generate', body: $data)->getStatusCode(),
        );
        self::assertSame(
            403,
            $this->request(
                method: 'POST',
                path: '/admin/permits/generate',
                body: $data + ['csrf' => 'wrong'],
            )->getStatusCode(),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        $csrf = $this->csrf($form);
        $invalid = $this->request(
            method: 'POST',
            path: '/admin/permits/generate',
            body: ['csrf' => $csrf, 'id' => '<script>bad</script>', 'plate' => [], 'term' => '31.02.2026'],
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', (string) $invalid->getBody());
        self::assertStringNotContainsString('<script>bad</script>', (string) $invalid->getBody());
        $generated = $this->request(method: 'POST', path: '/admin/permits/generate', body: $data + ['csrf' => $csrf]);
        self::assertSame(200, $generated->getStatusCode());
        self::assertSame('no-store', $generated->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Atļauja nr. new/1', (string) $generated->getBody());
        self::assertStringContainsString('The permit has been generated and saved.', (string) $generated->getBody());
        self::assertStringContainsString('/admin/permits/generate', (string) $generated->getBody());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertStringContainsString(
            'new/1',
            (string) $this->request(method: 'GET', path: '/admin/permits')->getBody(),
        );
        $this->request(method: 'POST', path: '/admin/logout', body: ['csrf' => $csrf]);
        self::assertSame(
            303,
            $this->request(
                method: 'POST',
                path: '/admin/permits/generate',
                body: $data + ['csrf' => $csrf],
            )->getStatusCode(),
        );
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
    }

    public function testLoginRotatesSessionAndSetsSecureCookie(): void
    {
        $form = $this->request(method: 'GET', path: '/admin/login');
        $anonymous = $this->cookies[AdminSessionCookie::COOKIE];
        $csrf = $this->csrf($form);
        $response = $this->request(
            method: 'POST',
            path: '/admin/login',
            body: ['csrf' => $csrf, 'username' => 'admin', 'password' => 'test-password'],
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertNotSame($anonymous, $this->cookies[AdminSessionCookie::COOKIE]);
        foreach (['Secure', 'HttpOnly', 'SameSite=Strict', 'Path=/admin', 'Max-Age=3600'] as $flag) {
            self::assertStringContainsString($flag, $response->getHeaderLine('Set-Cookie'));
        }
        self::assertSame(
            hash(algo: 'sha256', data: $this->cookies[AdminSessionCookie::COOKIE]),
            $this->connection->fetchOne('SELECT token_hash FROM admin_sessions WHERE authenticated = 1'),
        );
        self::assertSame(200, $this->request(method: 'GET', path: '/admin/permits')->getStatusCode());
        self::assertSame(
            '/admin/permits',
            $this->request(method: 'GET', path: '/admin/login')->getHeaderLine('Location'),
        );
        $this->cookies[AdminSessionCookie::COOKIE] = $anonymous;
        self::assertSame(
            '/admin/login',
            $this->request(method: 'GET', path: '/admin/permits')->getHeaderLine('Location'),
        );
    }

    public function testLoginOnLocalHttpOmitsSecureFlag(): void
    {
        $response = $this->request(method: 'GET', path: '/admin/login', secure: false);
        self::assertStringNotContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $response->getHeaderLine('Set-Cookie'));
    }

    public function testFailedLoginDoesNotRevealOrStoreCredentials(): void
    {
        $csrf = $this->csrf($this->request(method: 'GET', path: '/admin/login'));
        foreach ([['admin', 'wrong-password'], ['unknown-user', 'test-password']] as [$username, $password]) {
            $response = $this->request(
                method: 'POST',
                path: '/admin/login',
                body: ['csrf' => $csrf, 'username' => $username, 'password' => $password],
            );
            self::assertSame(401, $response->getStatusCode());
            self::assertStringContainsString('Invalid login or password.', (string) $response->getBody());
            self::assertStringNotContainsString($password, (string) $response->getBody());
        }
        self::assertTrue($this->logs->hasRecord('Admin login failed', Level::Warning));
        $loginContext = $this->logs->getRecords()[0]->context;
        self::assertSame(['request_id'], array_keys($loginContext));
        self::assertIsString($loginContext['request_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $loginContext['request_id']);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_sessions WHERE authenticated = 1'));
    }

    public function testLoginAndLogoutRequireCsrfTokens(): void
    {
        self::assertSame(
            403,
            $this->request(
                method: 'POST',
                path: '/admin/login',
                body: ['username' => 'admin', 'password' => 'test-password'],
            )->getStatusCode(),
        );
        $csrf = $this->csrf($this->request(method: 'GET', path: '/admin/login'));
        self::assertSame(
            403,
            $this->request(
                method: 'POST',
                path: '/admin/login',
                body: ['csrf' => 'wrong', 'username' => 'admin', 'password' => 'test-password'],
            )->getStatusCode(),
        );
        self::assertSame(
            400,
            $this->request(
                method: 'POST',
                path: '/admin/login',
                body: ['csrf' => $csrf, 'username' => [], 'password' => 'test-password'],
            )->getStatusCode(),
        );
        $this->login();
        self::assertSame(405, $this->request(method: 'GET', path: '/admin/logout')->getStatusCode());
        self::assertSame(
            403,
            $this->request(method: 'POST', path: '/admin/logout', body: ['csrf' => 'wrong'])->getStatusCode(),
        );
        self::assertSame(200, $this->request(method: 'GET', path: '/admin/permits')->getStatusCode());
        $authenticated = $this->cookies[AdminSessionCookie::COOKIE];
        $response = $this->request(
            method: 'POST',
            path: '/admin/logout',
            body: ['csrf' => $this->csrf($this->request(method: 'GET', path: '/admin/permits'))],
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertArrayNotHasKey(AdminSessionCookie::COOKIE, $this->cookies);
        $this->cookies[AdminSessionCookie::COOKIE] = $authenticated;
        self::assertSame(
            '/admin/login',
            $this->request(method: 'GET', path: '/admin/permits')->getHeaderLine('Location'),
        );
    }

    public function testLoginRateLimitSurvivesCookieReplacementAndExpires(): void
    {
        $csrf = $this->csrf($this->request(method: 'GET', path: '/admin/login'));
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::assertSame(
                401,
                $this->request(
                    method: 'POST',
                    path: '/admin/login',
                    body: ['csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong'],
                )->getStatusCode(),
            );
        }
        $this->cookies = [];
        $response = $this->request(
            method: 'POST',
            path: '/admin/login',
            body: [
                'csrf' => $this->csrf($this->request(method: 'GET', path: '/admin/login')),
                'username' => 'admin',
                'password' => 'test-password',
            ],
        );
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
        $this->now = $this->now->modify('+16 minutes');
        $this->login();
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_login_attempts'));
    }

    public function testAuthenticatedSessionExpiresAndPasswordChangesInvalidateIt(): void
    {
        $this->login();
        $this->now = $this->now->modify('+61 minutes');
        self::assertSame(
            '/admin/login',
            $this->request(method: 'GET', path: '/admin/permits')->getHeaderLine('Location'),
        );
        $this->login();
        $this->connection->update(
            table: 'app_settings',
            data: ['setting_value' => password_hash(
                password: 'changed',
                algo: PASSWORD_BCRYPT,
                options: ['cost' => 4],
            )],
            criteria: ['setting_key' => 'password_hash'],
        );
        self::assertSame(
            '/admin/login',
            $this->request(method: 'GET', path: '/admin/permits')->getHeaderLine('Location'),
        );
    }

    public function testChangingAdminUsernameInvalidatesExistingSessions(): void
    {
        $this->login();
        $this->connection->update(
            table: 'app_settings',
            data: ['setting_value' => 'new-admin'],
            criteria: ['setting_key' => 'admin_username'],
        );
        self::assertSame(
            '/admin/login',
            $this->request(method: 'GET', path: '/admin/permits')->getHeaderLine('Location'),
        );
    }

    #[DataProvider('reprintTypes')]
    public function testReprintPreservesTheOriginalPermitAndDoesNotWriteIssuanceData(bool $guest): void
    {
        $this->login();
        $csrf = $this->csrf($this->request(method: 'GET', path: '/admin/permits/generate'));
        $data = ['csrf' => $csrf, 'id' => 'DEMO-REPRINT', 'plate' => 'ab1234', 'apt' => '48', 'term' => '03.11.2026'];
        if ($guest) {
            $data['guest'] = 'on';
        }
        $original = $this->request(method: 'POST', path: '/admin/permits/generate', body: $data);
        self::assertSame(200, $original->getStatusCode());
        self::assertSame(1, preg_match(
            pattern: '/<style nonce="([^"]+)">/',
            subject: (string) $original->getBody(),
            matches: $nonce,
        ));
        self::assertStringContainsString(
            "'nonce-{$nonce[1]}'",
            $original->getHeaderLine('Content-Security-Policy'),
        );
        self::assertSame(2, $this->connection->fetchOne('SELECT signature_version FROM permits'));
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM permits');
        $path = $this->reprintPath('DEMO-REPRINT');
        $this->now = $this->now->modify('+15 minutes');
        $list = $this->request(method: 'GET', path: '/admin/permits');
        self::assertStringContainsString('action="' . $path . '"', (string) $list->getBody());
        self::assertStringContainsString('>Reprint</button>', (string) $list->getBody());
        $reprint = $this->request(
            method: 'POST',
            path: $path,
            body: ['csrf' => $this->csrf($list), 'id' => 'TAMPERED', 'term' => '01.01.2099', 'plate' => 'CHANGED'],
        );
        self::assertSame(200, $reprint->getStatusCode());
        self::assertSame('no-store', $reprint->getHeaderLine('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $reprint->getHeaderLine('Content-Type'));
        self::assertNotSame(
            $original->getHeaderLine('Content-Security-Policy'),
            $reprint->getHeaderLine('Content-Security-Policy'),
        );
        self::assertStringContainsString('The permit has been generated and saved.', (string) $original->getBody());
        self::assertStringContainsString('The existing permit is ready to print.', (string) $reprint->getBody());
        self::assertStringNotContainsString('/assets/permit-print.js', (string) $original->getBody());
        self::assertStringContainsString('src="/assets/permit-print.js?v=1"', (string) $reprint->getBody());
        $normalize = static fn(ResponseInterface $response): ?string => preg_replace(
            pattern: '~nonce="[^"]+"|<div id="action-notice"[^>]*>.*?</div>'
                . '|<script type="module" src="/assets/permit-print\.js\?v=1"></script>~s',
            replacement: '',
            subject: (string) $response->getBody(),
        );
        self::assertSame($normalize($original), $normalize($reprint));
        self::assertSame($rows, $this->connection->fetchAllAssociative('SELECT * FROM permits'));
        self::assertTrue($this->logs->hasRecord('Permit reprinted', Level::Info));
        $record = array_last($this->logs->getRecords());
        self::assertNotNull($record);
        self::assertSame([
            'guest' => $guest, 'request_id' => $reprint->getHeaderLine('X-Request-ID'),
        ], $record->context);
    }

    /** @return iterable<string, array{bool}> */
    public static function reprintTypes(): iterable
    {
        yield 'resident' => [false];
        yield 'guest' => [true];
    }

    #[DataProvider('reprintTypes')]
    public function testLegacyRecordsKeepTheirOriginalUnversionedQrUrls(bool $guest): void
    {
        $this->permit(id: 'DEMO-LEGACY', plate: 'TEST', guest: $guest, apartment: '48');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM permits');
        $this->login();
        $list = $this->request(method: 'GET', path: '/admin/permits');
        $response = $this->request(method: 'POST', path: $this->reprintPath('DEMO-LEGACY'), body: [
            'csrf' => $this->csrf($list),
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, preg_match(
            pattern: '/alt="([^"]+)"/',
            subject: (string) $response->getBody(),
            matches: $match,
        ));
        $url = html_entity_decode(string: $match[1], flags: ENT_QUOTES);
        /** @psalm-suppress WeakHash Check the original signature independently for each synthetic permit type. */
        $signature = md5(($guest ? '48' : 'TEST') . 'DEMO-LEGACY03.11.2026test-secret' . ($guest ? 'guest' : ''));
        self::assertStringContainsString('sign=' . $signature, $url);
        self::assertStringNotContainsString('&v=', $url);
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        self::assertStringContainsString(
            'Ir derīga',
            (string) $this->request(method: 'GET', path: '/p/check?' . $query)->getBody(),
        );
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM permits'));
    }

    public function testReprintRequiresLoginCsrfAndAValidRecordedPermit(): void
    {
        $this->permit('DEMO-VALID');
        $this->permit(id: 'DEMO-EXPIRED', term: '02.11.2026');
        $this->permit('DEMO-REVOKED');
        new BlacklistRepository($this->connection)->add('DEMO-REVOKED');
        $valid = $this->reprintPath('DEMO-VALID');
        $expired = $this->reprintPath('DEMO-EXPIRED');
        $revoked = $this->reprintPath('DEMO-REVOKED');
        $anonymous = $this->request(method: 'POST', path: $valid);
        self::assertSame(303, $anonymous->getStatusCode());
        self::assertSame('/admin/login', $anonymous->getHeaderLine('Location'));
        self::assertStringNotContainsString('DEMO-VALID', (string) $anonymous->getBody());
        $this->login();
        $list = $this->request(method: 'GET', path: '/admin/permits');
        $html = (string) $list->getBody();
        self::assertStringContainsString('action="' . $valid . '"', $html);
        self::assertStringNotContainsString('action="' . $expired . '"', $html);
        self::assertStringNotContainsString('action="' . $revoked . '"', $html);
        self::assertSame(405, $this->request(method: 'GET', path: $valid)->getStatusCode());
        self::assertSame(403, $this->request(method: 'POST', path: $valid)->getStatusCode());
        self::assertSame(403, $this->request(method: 'POST', path: $valid, body: ['csrf' => 'wrong'])->getStatusCode());
        $data = ['csrf' => $this->csrf($list)];
        self::assertSame(
            404,
            $this->request(
                method: 'POST',
                path: '/admin/permits/' . str_repeat('0', 64) . '/reprint',
                body: $data,
            )->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->request(
                method: 'POST',
                path: '/admin/permits/not-a-fingerprint/reprint',
                body: $data,
            )->getStatusCode(),
        );
        foreach ([$expired, $revoked] as $path) {
            $response = $this->request(method: 'POST', path: $path, body: $data);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringNotContainsString('data:image', (string) $response->getBody());
        }
        new BlacklistRepository($this->connection)->add('DEMO-VALID');
        self::assertSame(403, $this->request(method: 'POST', path: $valid, body: $data)->getStatusCode());
        self::assertSame(3, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
    }

    private function reprintPath(string $id): string
    {
        $fingerprint = $this->connection->fetchOne('SELECT fingerprint FROM permits WHERE permit_id = ?', [$id]);
        self::assertIsString($fingerprint);

        return '/admin/permits/' . $fingerprint . '/reprint';
    }

    public function testPermitListIsSearchableAndEscaped(): void
    {
        $this->permit('active/1');
        $this->permit(id: 'guest/2', plate: 'HIDDEN-GUEST-PLATE', guest: true, apartment: '48');
        $this->permit(id: 'expired/3', term: '01.01.2020');
        $this->permit('revoked/4');
        $this->permit('<script>alert(1)</script>');
        new BlacklistRepository($this->connection)->add('revoked/4');
        $this->login();
        $body = (string) $this->request(method: 'GET', path: '/admin/permits')->getBody();
        foreach (
            [
                'active/1',
                'guest/2',
                'Resident',
                'Guest',
                '48',
                'Valid',
                'Expired',
                'Revoked',
                'text-bg-success">Valid</span>',
                'text-bg-danger">Revoked</span>',
                'text-bg-secondary">Expired</span>',
                '03.11.2026 12:00',
            ] as $text
        ) {
            self::assertStringContainsString($text, $body);
        }
        self::assertStringNotContainsString('HIDDEN-GUEST-PLATE', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('test-secret', $body);
        $search = (string) $this->request(method: 'GET', path: '/admin/permits?q=guest%2F2')->getBody();
        self::assertStringContainsString('guest/2', $search);
        self::assertStringNotContainsString('active/1', $search);
        self::assertStringContainsString(
            'No permits match your search.',
            (string) $this->request(method: 'GET', path: '/admin/permits?q=missing')->getBody(),
        );
        self::assertSame(
            405,
            $this->request(method: 'POST', path: '/admin/permits', body: ['id' => 'changed'])->getStatusCode(),
        );
        self::assertSame(5, $this->connection->fetchOne('SELECT COUNT(*) FROM permits'));
        self::assertSame(400, $this->request(method: 'GET', path: '/admin/permits?page=0')->getStatusCode());
        self::assertSame(400, $this->request(method: 'GET', path: '/admin/permits?q[]=bad')->getStatusCode());
    }

    public function testZeroApartmentAndSearchAreNotTreatedAsEmpty(): void
    {
        $this->permit(id: 'zero-apartment', guest: true, apartment: '0');
        $this->login();
        $body = (string) $this->request(method: 'GET', path: '/admin/permits?q=0')->getBody();
        self::assertStringContainsString('zero-apartment', $body);
        self::assertStringContainsString('<td>0</td>', $body);
        self::assertStringContainsString('>Clear</a>', $body);
    }

    public function testPaginationAndLiteralWildcardSearch(): void
    {
        for ($number = 1; $number <= 26; ++$number) {
            $this->permit('row-' . $number);
        }
        $this->login();
        $first = (string) $this->request(method: 'GET', path: '/admin/permits')->getBody();
        self::assertSame(25, substr_count(haystack: $first, needle: 'scope="row"'));
        self::assertStringContainsString('Next', $first);
        $second = (string) $this->request(method: 'GET', path: '/admin/permits?page=2')->getBody();
        self::assertSame(1, substr_count(haystack: $second, needle: 'scope="row"'));
        self::assertStringContainsString('Previous', $second);
        self::assertStringContainsString(
            'No permits match your search.',
            (string) $this->request(method: 'GET', path: '/admin/permits?q=%25')->getBody(),
        );
    }
}
