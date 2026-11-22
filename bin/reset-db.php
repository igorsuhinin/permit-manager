<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\DevelopmentDatabaseResetter;
use Doctrine\DBAL\Connection;

require __DIR__ . '/../vendor/autoload.php';

if ('local' !== getenv('APP_ENV') || !in_array('--confirm', $argv ?? [], true)) {
    fwrite(STDERR, "Reset requires APP_ENV=local and --confirm. It deletes all test data.\n");
    exit(1);
}

/** @var Connection $connection */
$connection = require __DIR__ . '/../config/database.php';
/** @var array{settings: array<string, string>, blacklisted_ids: list<string>} $data */
$data = require __DIR__ . '/../config/initial-data.php';
new DevelopmentDatabaseResetter($connection)->reset($data);
echo "Local data reset: initial settings and blacklist restored; permits and admin sessions cleared.\n";
