<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Application\Port\LoginAttemptStoreInterface;
use Psr\Clock\ClockInterface;

final readonly class LoginRateLimiter
{
    private const int LIMIT = 5;
    private const int WINDOW = 900;

    public function __construct(private LoginAttemptStoreInterface $attempts, private ClockInterface $clock) {}

    public function retryAfter(string $address): int
    {
        $attempt = $this->attempts->find(hash(algo: 'sha256', data: $address));
        if (null === $attempt || $attempt['attempts'] < self::LIMIT) {
            return 0;
        }

        return max(0, $attempt['startedAt'] + self::WINDOW - $this->clock->now()->getTimestamp());
    }

    public function fail(string $address): void
    {
        $this->attempts->fail(
            addressHash: hash(algo: 'sha256', data: $address),
            now: $this->clock->now()->getTimestamp(),
            window: self::WINDOW,
        );
    }

    public function clear(string $address): void
    {
        $this->attempts->clear(hash(algo: 'sha256', data: $address));
    }
}
