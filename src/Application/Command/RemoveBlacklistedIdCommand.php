<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class RemoveBlacklistedIdCommand
{
    public function __construct(public string $id, public string $reason, public string $actor) {}
}
