<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;

$env = static function (string $name, string $fallback): string {
    $value = getenv($name);

    return false === $value || '' === $value ? $fallback : $value;
};

return DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => $env(name: 'DB_HOST', fallback: '127.0.0.1'),
    'port' => (int) $env(name: 'DB_PORT', fallback: '3306'),
    'dbname' => $env(name: 'DB_DATABASE', fallback: 'permits'),
    'user' => $env(name: 'DB_USERNAME', fallback: 'permits'),
    'password' => $env(name: 'DB_PASSWORD', fallback: ''),
    'charset' => 'utf8mb4',
]);
