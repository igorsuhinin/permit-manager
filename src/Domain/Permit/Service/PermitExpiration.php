<?php

declare(strict_types=1);

namespace App\Domain\Permit\Service;

final class PermitExpiration
{
    public static function parse(string $term, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (str_contains(haystack: $term, needle: "\0")) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(format: '!d.m.Y', datetime: $term, timezone: $timezone);

        return false !== $date && $date->format('d.m.Y') === $term ? $date->setTime(
            hour: 23,
            minute: 59,
            second: 59,
        ) : null;
    }
}
