<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Clock\ClockInterface;

final readonly class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $time) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }
}
