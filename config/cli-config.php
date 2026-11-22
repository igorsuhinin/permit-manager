<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;

/** @var Connection $connection */
$connection = require __DIR__ . '/database.php';

return DependencyFactory::fromConnection(new ConfigurationArray([
    'migrations_paths' => ['Database\\Migrations' => __DIR__ . '/../migrations'],
    'table_storage' => ['table_name' => 'doctrine_migration_versions'],
    'transactional' => false,
]), new ExistingConnection($connection));
