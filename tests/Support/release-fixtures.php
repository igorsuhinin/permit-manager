<?php

declare(strict_types=1);

use App\Application\Command\GeneratePermitCommand;
use App\Application\Command\GeneratePermitHandler;
use App\Domain\Permit\Model\Permit;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use Composer\InstalledVersions;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$root = getcwd();
if (false === $root) {
    throw new RuntimeException('Cannot resolve the disposable application directory.');
}
/** @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests. */
require $root . '/vendor/autoload.php';
$arguments = $argv ?? [];
if (in_array('--startup-error', $arguments, true) || in_array('--wrong-root', $arguments, true)) {
    $startup = in_array('--startup-error', $arguments, true);
    $status = $startup ? '500' : '403';
    $path = $startup ? '/health' : '/composer.json';
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        http_clear_last_response_headers();
        $body = @file_get_contents(
            filename: 'http://127.0.0.1' . $path,
            context: stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]),
        );
        /** @var list<string> $headers */
        $headers = http_get_last_response_headers() ?? [];
        if (str_contains(haystack: $headers[0] ?? '', needle: ' ' . $status . ' ')) {
            if ($startup && '500 Internal Server Error' !== $body) {
                throw new RuntimeException('Startup errors must not expose internal details.');
            }
            if ($startup) {
                $ids = array_filter($headers, static fn(string $header): bool => 1 === preg_match(
                    pattern: '/^X-Request-ID: [a-f0-9]{32}$/i',
                    subject: $header,
                ));
                if (1 !== count($ids)) {
                    throw new RuntimeException('Startup failure must include one safe server-generated request ID.');
                }
                foreach (
                    [
                        'Cache-Control: no-store', 'Referrer-Policy: no-referrer', 'X-Content-Type-Options: nosniff',
                        'X-Frame-Options: DENY',
                        "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; "
                            . "base-uri 'none'; form-action 'self'",
                    ] as $expected
                ) {
                    if (!in_array($expected, $headers, true)) {
                        throw new RuntimeException('Startup errors must retain security and cache headers.');
                    }
                }
            }
            echo 'Confirmed generic HTTP ' . $status . " response.\n";
            exit;
        }
        usleep(100000);
    }

    throw new RuntimeException('The expected safe failure response was not received.');
}
if (
    InstalledVersions::isInstalled('phpunit/phpunit') || extension_loaded('pcov')
    || is_dir('tests') || is_file('bin/reset-db.php') || is_file('IMPROVEMENT_PLAN.md') || is_file('.env')
) {
    throw new RuntimeException('The release contains development tooling or private files.');
}
/**
 * @var Connection $connection
 *
 * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
 */
$connection = require $root . '/config/database.php';
$blacklist = new BlacklistRepository($connection);
if (in_array('--verify', $arguments, true)) {
    if (
        1 !== $connection->fetchOne('SELECT COUNT(*) FROM permits') || !$blacklist->contains('SMOKE-REVOKED')
        || 2 !== $connection->fetchOne('SELECT signature_version FROM permits')
        || 1 !== $connection->fetchOne('SELECT COUNT(*) FROM admin_audit')
        || $connection->fetchOne('SELECT fingerprint FROM permits')
            !== $connection->fetchOne('SELECT target_hash FROM admin_audit')
    ) {
        throw new RuntimeException('Application data was lost during a release switch.');
    }
    echo "Issuance, audit, and blacklist data survived release switching.\n";
    exit;
}
$blacklist->add('SMOKE-REVOKED');
$builder = new ContainerBuilder();
$builder->addDefinitions($root . '/config/container.php');
$builder->addDefinitions([Connection::class => $connection, LoggerInterface::class => new NullLogger()]);
/** @var GeneratePermitHandler $issue */
$issue = $builder->build()->get(GeneratePermitHandler::class);
$issue->handle(new GeneratePermitCommand(
    permit: new Permit(id: 'SMOKE-STORED', term: '31.12.2099', plate: 'TEST'),
    actor: 'admin',
));
$checks = [];
foreach (['SMOKE-RESIDENT', 'SMOKE-GUEST', 'SMOKE-REVOKED'] as $id) {
    $query = ['id' => $id, 'term' => '31.12.2099'];
    $guest = 'SMOKE-GUEST' === $id;
    $query += $guest ? ['guest' => '1', 'apt' => '48'] : ['plate' => 'AB1234'];
    /** @psalm-suppress WeakHash Generate independent legacy fixtures to check release compatibility. */
    $signature = md5(($guest ? '48' : 'AB1234') . $id . '31.12.2099test-secret' . ($guest ? 'guest' : ''));
    $query['sign'] = $signature;
    $checks[] = ['query' => $query, 'contains' => 'SMOKE-REVOKED' === $id ? 'Atļauja ir anulēta' : 'Ir derīga'];
}
$key = new SettingsRepository($connection)->load()->hmacKey;
foreach ([false, true] as $guest) {
    $id = $guest ? 'SMOKE-HMAC-GUEST' : 'SMOKE-HMAC-RESIDENT';
    $payload = '14:parking-permit1:2'
        . ($guest ? '5:guest16:SMOKE-HMAC-GUEST' : '8:resident19:SMOKE-HMAC-RESIDENT')
        . '10:31.12.2099' . ($guest ? '2:48' : '6:AB1234');
    $query = ['id' => $id, 'term' => '31.12.2099', 'v' => '2',
        'sign' => hash_hmac(algo: 'sha256', data: $payload, key: $key)];
    $query += $guest ? ['guest' => '1', 'apt' => '48'] : ['plate' => 'AB1234'];
    $checks[] = ['query' => $query, 'contains' => 'Ir derīga'];
}
echo json_encode(value: $checks, flags: JSON_THROW_ON_ERROR);
