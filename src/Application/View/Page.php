<?php

declare(strict_types=1);

namespace App\Application\View;

/** @template T */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(public array $items, public bool $hasNext) {}
}
