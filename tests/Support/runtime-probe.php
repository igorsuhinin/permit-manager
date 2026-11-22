<?php

declare(strict_types=1);

// Copied into the disposable benchmark document root only; never packaged.
if ('browser' !== getenv('APP_ENV') || 'mysql-test' !== getenv('DB_HOST')) {
    http_response_code(404);
    exit;
}
$reset = '1' === ($_GET['reset'] ?? null);
if ($reset && function_exists('opcache_reset') && !opcache_reset()) {
    throw new RuntimeException('Could not reset the disposable web OPcache.');
}
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
/** @var array<array-key, mixed> $statistics */
$statistics = is_array($status) && is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
header('Content-Type: application/json');
echo json_encode(value: [
    'php' => PHP_VERSION,
    'apache' => function_exists('apache_get_version') ? apache_get_version() : null,
    'sapi' => PHP_SAPI,
    'architecture' => php_uname('m'),
    'opcache_enabled' => is_array($status) && true === ($status['opcache_enabled'] ?? false),
    'validate_timestamps' => ini_get('opcache.validate_timestamps'),
    'revalidate_freq' => ini_get('opcache.revalidate_freq'),
    'file_update_protection' => ini_get('opcache.file_update_protection'),
    'memory_consumption' => ini_get('opcache.memory_consumption'),
    'jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
    'jit' => ini_get('opcache.jit'),
    'cached_scripts' => $statistics['num_cached_scripts'] ?? null,
    'hits' => $statistics['hits'] ?? null,
    'application_timestamp' => filemtime(__DIR__ . '/index.php'),
    'application_cached' => function_exists('opcache_is_script_cached')
        && opcache_is_script_cached(__DIR__ . '/index.php'),
    'reset_requested' => $reset,
], flags: JSON_THROW_ON_ERROR);
