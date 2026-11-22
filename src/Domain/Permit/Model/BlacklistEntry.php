<?php

declare(strict_types=1);

namespace App\Domain\Permit\Model;

final readonly class BlacklistEntry
{
    public ?string $reason;

    public function __construct(public string $id, string $reason = '')
    {
        $this->reason = '' === trim($reason) ? null : $reason;
    }
}
