<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;

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
/**
 * @var Connection $connection
 *
 * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
 */
$connection = require $root . '/config/database.php';
$snapshot = [];
foreach (
    [
        'app_settings' => 'setting_key', 'blacklisted_ids' => 'id_hash', 'permits' => 'fingerprint',
        'permit_checks' => 'id', 'admin_sessions' => 'token_hash', 'admin_login_attempts' => 'ip_hash',
        'doctrine_migration_versions' => 'version', 'admin_audit' => 'id',
    ] as $table => $key
) {
    $snapshot[$table] = hash(algo: 'sha256', data: serialize($connection->fetchAllAssociative(
        'SELECT * FROM ' . $table . ' ORDER BY ' . $key,
    )));
}
foreach (
    ['public-ui.php', 'initial-blacklist.php', 'templates/permit.css.twig', 'templates/public-check.css.twig'] as $file
) {
    $hash = hash_file(algo: 'sha256', filename: '/var/www/releases/shared/' . $file);
    if (false === $hash) {
        throw new RuntimeException('Private configuration was not restored.');
    }
    $snapshot['private/' . $file] = $hash;
}
if ('--snapshot' === ($argv[1] ?? '')) {
    echo json_encode(value: $snapshot, flags: JSON_THROW_ON_ERROR);
} else {
    /**
     * The isolated recovery runner selects a local snapshot; stream wrappers are not accepted.
     *
     * @psalm-taint-escape file
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     */
    $snapshotPath = realpath($argv[2] ?? '');
    $expected = false === $snapshotPath ? false : file_get_contents($snapshotPath);
    if (
        false === $expected
        || json_decode(json: $expected, associative: true, flags: JSON_THROW_ON_ERROR) !== $snapshot
    ) {
        throw new RuntimeException('Database rows, migration history, keys, or private files changed on restore.');
    }
    echo "Restored table contents, migration history, credentials, keys, and private configuration exactly.\n";
}
