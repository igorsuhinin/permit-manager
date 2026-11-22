<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Port\LogContextInterface;

final class RequestLogContext implements LogContextInterface
{
    private ?string $current = null;
    /** @var list<?string> */
    private array $parents = [];

    public function __construct(private ?string $initialId = null)
    {
        if (null !== $initialId && 1 !== preg_match(pattern: '/\A[a-f0-9]{32}\z/', subject: $initialId)) {
            throw new \InvalidArgumentException('Invalid internal request ID.');
        }
    }

    #[\Override]
    public function begin(): string
    {
        $id = $this->initialId ?? bin2hex(random_bytes(16));
        $this->initialId = null;
        $this->parents[] = $this->current;
        $this->current = $id;

        return $id;
    }

    #[\Override]
    public function end(): void
    {
        if ([] === $this->parents) {
            throw new \LogicException('No request log context is active.');
        }
        $this->current = array_pop($this->parents);
    }

    #[\Override]
    public function requestId(): ?string
    {
        return $this->current;
    }
}
