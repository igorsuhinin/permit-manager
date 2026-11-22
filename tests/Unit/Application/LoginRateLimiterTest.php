<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Authentication\LoginRateLimiter;
use App\Application\Port\LoginAttemptStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

#[Small]
#[CoversClass(LoginRateLimiter::class)]
final class LoginRateLimiterTest extends TestCase
{
    #[DataProvider('attemptWindows')]
    public function testCooldownBoundaries(?int $attempts, int $elapsed, int $expected): void
    {
        $now = new \DateTimeImmutable('2026-11-03T12:00:00Z');
        $store = $this->createMock(LoginAttemptStoreInterface::class);
        $store->expects($this->once())->method('find')->with(hash(algo: 'sha256', data: '192.0.2.1'))
            ->willReturn(null === $attempts ? null : [
                'attempts' => $attempts,
                'startedAt' => $now->getTimestamp() - $elapsed,
            ]);
        self::assertSame($expected, new LoginRateLimiter($store, new FrozenClock($now))->retryAfter('192.0.2.1'));
    }

    /** @return iterable<string, array{?int, int, int}> */
    public static function attemptWindows(): iterable
    {
        yield 'no failures' => [null, 0, 0];
        yield 'below limit' => [4, 0, 0];
        yield 'limit reached' => [5, 0, 900];
        yield 'last second' => [5, 899, 1];
        yield 'window expires' => [5, 900, 0];
        yield 'window expired' => [6, 901, 0];
    }

    public function testFailureAndClearingUseOnlyAddressHashes(): void
    {
        $now = new \DateTimeImmutable('2026-11-03T12:00:00Z');
        $store = $this->createMock(LoginAttemptStoreInterface::class);
        $hash = hash(algo: 'sha256', data: '192.0.2.1');
        $store->expects($this->once())->method('fail')->with($hash, $now->getTimestamp(), 900);
        $store->expects($this->once())->method('clear')->with($hash);
        $limiter = new LoginRateLimiter($store, new FrozenClock($now));
        $limiter->fail('192.0.2.1');
        $limiter->clear('192.0.2.1');
    }
}
