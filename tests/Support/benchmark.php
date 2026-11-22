<?php

declare(strict_types=1);

use App\Application\Authentication\AdminSessionService;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Twig\Environment;

if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit(1);
}


$root = getcwd();
if (false === $root) {
    throw new RuntimeException('Cannot resolve the disposable application directory.');
}
/** @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests. */
require $root . '/vendor/autoload.php';
if ('browser' !== getenv('APP_ENV') || 'mysql-test' !== getenv('DB_HOST') || 'permits_test' !== getenv('DB_DATABASE')) {
    throw new RuntimeException('Benchmarks require the disposable browser environment.');
}
$builder = new ContainerBuilder();
$builder->addDefinitions($root . '/config/container.php');
$container = $builder->build();
/** @var AdminSessionService $sessions */
$sessions = $container->get(AdminSessionService::class);
$session = $sessions->login($sessions->open(null));
/** @var Connection $connection */
$connection = $container->get(Connection::class);
/** @var Environment $twig */
$twig = $container->get(Environment::class);
$cache = $twig->getCache();
$sequence = 0;
/** @var array{legacy: array{resident: array<string, string>}} $fixtures */
$fixtures = json_decode(
    json: (string) file_get_contents('/var/www/demo/fixtures/demo.json'),
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

/** @param array<string, string> $form
 * @return array{body: string, ms: float}
 */
$request = static function (string $path, array $form = []) use ($session): array {
    $curl = curl_init('http://127.0.0.1' . $path);
    if (false === $curl) {
        throw new RuntimeException('Could not create the benchmark HTTP client.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    if ('/admin/login' !== $path) {
        curl_setopt($curl, CURLOPT_COOKIE, 'admin_session=' . $session->token);
    }
    if ([] !== $form) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    }
    $start = hrtime(true);
    $body = curl_exec($curl);
    $elapsed = (float) (hrtime(true) - $start) / 1e6;
    if (!is_string($body) || 200 !== curl_getinfo($curl, CURLINFO_RESPONSE_CODE)) {
        throw new RuntimeException('A benchmark request failed: ' . $path);
    }

    return ['body' => $body, 'ms' => $elapsed];
};
/** @return array{body: string, ms: float} */
$generation = static function (bool $guest) use ($request, $session, &$sequence): array {
    $response = $request('/admin/permits/generate', [
        'csrf' => $session->csrfToken, 'id' => 'BENCH-' . ++$sequence,
        'plate' => 'AB1234', 'apt' => '12', 'term' => '31.12.2099',
    ] + ($guest ? ['guest' => 'on'] : []));
    if (!str_contains(haystack: $response['body'], needle: 'class="qr"')) {
        throw new RuntimeException('The generation benchmark did not produce a permit.');
    }

    return $response;
};
/** @var array<string, Closure(): array{body: string, ms: float}> $scenarios */
$scenarios = [
    'public_check' => static fn(): array => $request('/p/check?' . http_build_query($fixtures['legacy']['resident'])),
    'login_page' => static fn(): array => $request('/admin/login'),
    'admin_permits' => static fn(): array => $request('/admin/permits'),
    'generation_form' => static fn(): array => $request('/admin/permits/generate'),
    'issue_resident' => static fn(): array => $generation(false),
    'issue_guest' => static fn(): array => $generation(true),
];
foreach (['legacy' => '1001', 'hmac' => '1002'] as $name => $id) {
    $fingerprint = $connection->fetchOne('SELECT fingerprint FROM permits WHERE permit_id = ?', [$id]);
    if (!is_string($fingerprint)) {
        throw new RuntimeException('The benchmark reprint fixture is missing.');
    }
    $scenarios['reprint_' . $name] = static fn(): array => $request(
        '/admin/permits/' . $fingerprint . '/reprint',
        ['csrf' => $session->csrfToken],
    );
}
$results = [];
foreach ($scenarios as $name => $scenario) {
    if (is_string($cache)) {
        if (!str_starts_with(haystack: $cache, needle: '/tmp/permit-benchmark-twig/')) {
            throw new RuntimeException('Only the disposable benchmark template cache may be cleared.');
        }
        if (is_dir($cache)) {
            $files = new RecursiveIteratorIterator(
                iterator: new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
                mode: RecursiveIteratorIterator::CHILD_FIRST,
            );
            /** @var SplFileInfo|string $file */
            foreach ($files as $file) {
                if ($file instanceof SplFileInfo) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
            }
        }
    }
    $request('/__benchmark.php?reset=1');
    $first = $scenario()['ms'];
    for ($iteration = 0; $iteration < 3; ++$iteration) {
        $scenario();
    }
    // Let the Docker runtime's two-second OPcache file-update protection elapse.
    sleep(3);
    $samples = [];
    for ($iteration = 0; $iteration < 20; ++$iteration) {
        $samples[] = $scenario()['ms'];
    }
    sort($samples);
    $results[$name] = [
        'first_ms' => round(num: $first, precision: 3),
        'warm_median_ms' => round(num: ($samples[9] + $samples[10]) / 2.0, precision: 3),
        'warm_p95_ms' => round(num: $samples[18], precision: 3),
        'samples_ms' => $samples,
    ];
}
/** @psalm-var mixed $runtime */
$runtime = json_decode(json: $request('/__benchmark.php')['body'], associative: true, flags: JSON_THROW_ON_ERROR);
if (
    is_array($runtime) && is_int($runtime['application_timestamp'] ?? null) && $runtime['application_timestamp'] > 0
    && true === ($runtime['opcache_enabled'] ?? null) && true !== ($runtime['application_cached'] ?? null)
) {
    throw new RuntimeException('The production entry point was not cached after warm requests.');
}
fwrite(stream: STDOUT, data: json_encode(value: [
    'recorded_at_utc' => gmdate('c'),
    'artifact_sha256' => $argv[1] ?? '',
    'runtime' => $runtime,
    'database' => $connection->fetchOne('SELECT VERSION()'),
    'twig_cache_enabled' => false !== $cache,
    'samples_per_scenario' => 20,
    'warmup_requests' => 3,
    'settle_seconds' => 3,
    'concurrency' => 1,
    'results' => $results,
], flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
