<?php

declare(strict_types=1);

namespace App\Application\Port;

interface LoginAttemptStoreInterface
{
    /** @return array{attempts: int, startedAt: int}|null */
    public function find(string $addressHash): ?array;

    public function fail(string $addressHash, int $now, int $window): void;

    public function clear(string $addressHash): void;
}
