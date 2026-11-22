<?php

declare(strict_types=1);

use Twig\Environment;

$root = getcwd();
if (
    false === $root || 'mysql-test' !== getenv('DB_HOST')
    || !str_starts_with(haystack: $root, needle: '/var/www/releases/')
) {
    throw new RuntimeException('Cache fixtures require the disposable release environment.');
}
/** @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests. */
require $root . '/vendor/autoload.php';
$timestamp = filemtime($root . '/public/index.php');
if (false === $timestamp || $timestamp <= 0) {
    throw new RuntimeException('Release timestamps must allow opcode caching.');
}
/**
 * @var Environment $twig
 *
 * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
 */
$twig = require $root . '/config/templates.php';
$cache = $twig->getCache();
if (!is_string($cache)) {
    throw new RuntimeException('Release verification requires an enabled template cache.');
}
if ('--path' === ($argv[1] ?? null)) {
    echo $cache;
    exit;
}
$marker = $argv[1] ?? '';
if (!in_array($marker, ['a', 'b'], true)) {
    throw new RuntimeException('A synthetic cache marker is required.');
}
$query = [
    'id' => 'CACHE-CHECK', 'term' => '31.12.2099', 'plate' => 'AB1234',
    'sign' => '04861d51c00be61ce83a1c7aefbc7ed7',
];
$body = file_get_contents('http://127.0.0.1/p/check?' . http_build_query($query));
if (
    false === $body || !str_contains(haystack: $body, needle: 'cache-marker-' . $marker)
    || !str_contains(haystack: $body, needle: 'Ir derīga')
) {
    throw new RuntimeException('The release served a stale private template.');
}
if ([] === glob($cache . '/*/*.php')) {
    throw new RuntimeException('The HTTP request did not use the selected template cache.');
}
echo "Release template cache and private override verified.\n";
