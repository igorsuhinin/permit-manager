<?php

declare(strict_types=1);

use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\PermitRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use Doctrine\DBAL\Connection;

$root = getcwd();
if (false === $root) {
    throw new RuntimeException('Cannot resolve the disposable application directory.');
}
/** @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests. */
require $root . '/vendor/autoload.php';
if ('browser' !== getenv('APP_ENV') || 'mysql-test' !== getenv('DB_HOST') || 'permits_test' !== getenv('DB_DATABASE')) {
    throw new RuntimeException('Browser fixtures require the disposable browser environment.');
}
if (in_array('--links', $argv ?? [], true)) {
    /** @var array{origin: string, legacy: array<string, array<string, string>>} $fixtures */
    $fixtures = json_decode(
        json: (string) file_get_contents('/var/www/demo/fixtures/demo.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    foreach ($fixtures['legacy'] as $label => $query) {
        echo $label . ': ' . $fixtures['origin'] . '/p/check.php?' . http_build_query($query) . "\n";
    }
    exit;
}
/**
 * @var Connection $connection
 *
 * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
 */
$connection = require $root . '/config/database.php';
if (
    0 !== $connection->fetchOne('SELECT COUNT(*) FROM permits')
    || 0 !== $connection->fetchOne('SELECT COUNT(*) FROM admin_audit')
) {
    throw new RuntimeException('Browser fixtures require an empty disposable database.');
}
$permits = new PermitRepository($connection);
$created = new DateTimeImmutable('2026-11-11T12:00:00Z');
$expiration = new DateTimeImmutable('2099-12-31T23:59:59+02:00');
$permits->save(new Permit(id: '1001', term: '31.12.2099', plate: 'AB1234', apartment: '12'), $expiration, $created);
$permits->save(new Permit(
    id: '1002',
    term: '31.12.2099',
    guest: true,
    apartment: '12',
    signatureVersion: PermitSignatureVersion::HmacSha256,
), $expiration, $created);
new BlacklistRepository($connection)->add(id: '9003', reason: 'Synthetic revoked permit');
$legacy = [];
foreach (['resident' => '9001', 'guest' => '9002', 'revoked' => '9003', 'expired' => '9004'] as $label => $id) {
    $term = 'expired' === $label ? '01.01.2020' : '31.12.2099';
    $guest = 'guest' === $label;
    $query = ['id' => $id, 'term' => $term] + ($guest ? ['guest' => '1', 'apt' => '12'] : ['plate' => 'AB1234']);
    /** @psalm-suppress WeakHash Generate an independent legacy signature for these synthetic permits. */
    $signature = md5(($guest ? '12' : 'AB1234') . $id . $term . 'test-secret' . ($guest ? 'guest' : ''));
    $legacy[$label] = $query + ['sign' => $signature];
}
echo json_encode(value: [
    'origin' => new SettingsRepository($connection)->load()->baseUrl,
    'legacy' => $legacy,
], flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
