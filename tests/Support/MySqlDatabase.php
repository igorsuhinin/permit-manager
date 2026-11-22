<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class MySqlDatabase
{
    // This endpoint exists only inside compose.test.yaml's isolated network.
    public static function connect(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => 'mysql-test',
            'dbname' => 'permits_test',
            'user' => 'permits_test',
            'password' => 'permits_test',
            'charset' => 'utf8mb4',
        ]);
    }

    public static function migrate(string $version = 'latest'): void
    {
        $output = tmpfile();
        if (false === $output) {
            throw new \RuntimeException('Could not capture the MySQL test migration output.');
        }
        try {
            $process = proc_open(
                command: [PHP_BINARY, 'vendor/bin/doctrine-migrations', 'migrate', $version,
                    '--no-interaction', '--allow-no-migration'],
                descriptor_spec: [1 => $output, 2 => $output],
                pipes: $pipes,
                cwd: __DIR__ . '/../..',
                env_vars: array_merge(getenv(), [
                    'DB_HOST' => 'mysql-test', 'DB_PORT' => '3306', 'DB_DATABASE' => 'permits_test',
                    'DB_USERNAME' => 'permits_test', 'DB_PASSWORD' => 'permits_test',
                ]),
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start the MySQL test migration.');
            }
            if (0 !== proc_close($process)) {
                rewind($output);

                throw new \RuntimeException('MySQL test migration failed: ' . (string) stream_get_contents($output));
            }
        } finally {
            fclose($output);
        }
    }
}
