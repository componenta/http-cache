<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

final class EntityTag
{
    /**
     * @param list<string> $ifNoneMatchValues
     */
    public static function ifNoneMatch(array $ifNoneMatchValues, ?string $current): bool
    {
        foreach (HeaderList::split($ifNoneMatchValues) as $candidate) {
            if ($candidate === '*') {
                return true;
            }

            if ($current !== null && self::weaklyEqual($candidate, $current)) {
                return true;
            }
        }

        return false;
    }

    public static function weaklyEqual(string $first, string $second): bool
    {
        $first = self::opaqueTag($first);
        $second = self::opaqueTag($second);

        return $first !== null && $second !== null && $first === $second;
    }

    private static function opaqueTag(string $value): ?string
    {
        $value = trim($value);

        if (str_starts_with($value, 'W/')) {
            $value = substr($value, 2);
        }

        return preg_match('/^"[\x21\x23-\x7E\x80-\xFF]*"$/D', $value) === 1 ? $value : null;
    }

    private function __construct() {}
}
