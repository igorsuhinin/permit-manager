<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Migrations\Version20261105000000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;

final class Database
{
    public static function create(bool $seed = true): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261105000000($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }
        if (!$seed) {
            $connection->executeStatement('DELETE FROM blacklisted_ids');
            $connection->executeStatement('DELETE FROM app_settings');
        }

        return $connection;
    }
}
