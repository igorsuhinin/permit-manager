<?php

declare(strict_types=1);

namespace App\Application\Port;

interface TransactionInterface
{
    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function run(\Closure $operation): mixed;
}
