<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\HttpDate;

/** @internal */
final class AgeMetadata
{
    /** @param array<string, list<string>> $headers Lowercase field names. */
    public static function minimumAt(array $headers, int $now): int
    {
        return max(
            self::apparentAge(implode(', ', $headers['date'] ?? []), (float) $now),
            self::ageValue($headers['age'] ?? []),
        );
    }

    public static function apparentAge(string $date, float $responseTime): int
    {
        if ($date === '') {
            return 0;
        }

        $dateValue = HttpDate::parse($date, (int) $responseTime);

        if ($dateValue === null) {
            return 0;
        }

        $elapsed = $responseTime - $dateValue;

        return $elapsed >= PHP_INT_MAX ? PHP_INT_MAX : max(0, (int) $elapsed);
    }

    /** @param array<string> $values */
    public static function ageValue(array $values): int
    {
        $values = HeaderList::split($values);

        if ($values === []) {
            return 0;
        }

        $value = $values[0];

        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return 0;
        }

        $normalized = ltrim($value, '0');

        if ($normalized === '') {
            return 0;
        }

        $max = (string) PHP_INT_MAX;

        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)
        ) {
            return PHP_INT_MAX;
        }

        return (int) $normalized;
    }

    private function __construct() {}
}
