<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\TransactionInterface;

final readonly class ImmediateTransaction implements TransactionInterface
{
    #[\Override]
    public function run(\Closure $operation): mixed
    {
        return $operation();
    }
}
