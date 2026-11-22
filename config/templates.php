<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$root = dirname(__DIR__);
$environment = getenv('APP_ENV');
$development = in_array($environment, ['local', 'test'], true);
$enabled = getenv('TWIG_CACHE_ENABLED');
if (false !== $enabled && '' !== $enabled && !in_array($enabled, ['0', '1'], true)) {
    throw new InvalidArgumentException('TWIG_CACHE_ENABLED must be 0 or 1.');
}
$enabled = false === $enabled || '' === $enabled ? !$development : '1' === $enabled;
$cache = getenv('TWIG_CACHE_DIRECTORY');
$cache = false === $cache || '' === $cache ? 'protected/cache/twig' : $cache;
$cache = str_starts_with(haystack: $cache, needle: '/') ? $cache : $root . '/' . $cache;
$version = getenv('TWIG_CACHE_VERSION');
$version = false === $version ? '' : $version;
// A shared cache base still isolates physical releases; bump the version for in-place edits.
$cache .= '/' . substr(string: hash(algo: 'sha256', data: $root . "\0" . $version), offset: 0, length: 20);

$loader = new FilesystemLoader(__DIR__ . '/../templates');
$directory = getenv('PUBLIC_TEMPLATE_DIRECTORY');
$directory = false === $directory || '' === $directory ? 'protected/templates' : $directory;
$directory = str_starts_with(haystack: $directory, needle: '/') ? $directory : __DIR__ . '/../' . $directory;
if (is_dir($directory)) {
    $loader->prependPath($directory);
}

return new Environment($loader, [
    'autoescape' => 'html',
    'strict_variables' => true,
    'debug' => false,
    'auto_reload' => $development,
    'cache' => $enabled ? $cache : false,
]);
