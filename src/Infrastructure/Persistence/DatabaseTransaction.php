<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\TransactionInterface;
use Doctrine\DBAL\Connection;

final readonly class DatabaseTransaction implements TransactionInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function run(\Closure $operation): mixed
    {
        return $this->connection->transactional(static fn() => $operation());
    }
}
