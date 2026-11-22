<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class ListPermitsQuery
{
    public function __construct(public string $search, public int $page) {}
}
