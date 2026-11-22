<?php

declare(strict_types=1);

$env = static function (string $name, ?string $fallback = null): ?string {
    $value = getenv($name);

    return false === $value || '' === $value ? $fallback : $value;
};

return [
    'dsn' => $env('SENTRY_DSN'),
    'environment' => $env(name: 'SENTRY_ENVIRONMENT', fallback: $env(name: 'APP_ENV', fallback: 'production')),
    'release' => $env('SENTRY_RELEASE'),
];
