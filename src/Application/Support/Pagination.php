<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\View\Page;

final class Pagination
{
    public const int LIMIT = 25;
    public const int FETCH_LIMIT = self::LIMIT + 1;

    public static function offset(int $page): int
    {
        return ($page - 1) * self::LIMIT;
    }

    /** @template T
     * @param list<T> $items
     *
     * @return Page<T>
     */
    public static function page(array $items): Page
    {
        $hasNext = count($items) > self::LIMIT;
        if ($hasNext) {
            array_pop($items);
        }

        return new Page($items, $hasNext);
    }
}
