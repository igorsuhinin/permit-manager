<?php

declare(strict_types=1);

namespace App\Application\Port;

interface LogContextInterface
{
    public function begin(): string;

    public function end(): void;

    /** @psalm-mutation-free */
    public function requestId(): ?string;
}
