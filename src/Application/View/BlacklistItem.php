<?php

declare(strict_types=1);

namespace App\Application\View;

final readonly class BlacklistItem
{
    public function __construct(public string $id, public ?string $reason) {}
}
