<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

final class EntityTag
{
    /**
     * @param array<string> $ifNoneMatchValues
     */
    public static function ifNoneMatch(array $ifNoneMatchValues, ?string $current): bool
    {
        // Entity-tag opaque values are not quoted-string: backslash is literal.
        $candidates = self::splitTags($ifNoneMatchValues);

        if ($candidates === []) {
            return false;
        }

        if (in_array('*', $candidates, true)) {
            return count($candidates) === 1;
        }

        foreach ($candidates as $candidate) {
            if (self::opaqueTag($candidate) === null) {
                return false;
            }
        }

        if ($current === null) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (self::weaklyEqual($candidate, $current)) {
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

    /** @param array<string> $values @return list<string> */
    private static function splitTags(array $values): array
    {
        $parts = [];
        $start = 0;
        $quoted = false;
        $value = implode(',', $values);
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if ($value[$i] === '"') {
                $quoted = !$quoted;
            } elseif ($value[$i] === ',' && !$quoted) {
                $part = trim(substr($value, $start, $i - $start));
                if ($part !== '') {
                    $parts[] = $part;
                }
                $start = $i + 1;
            }
        }

        $part = trim(substr($value, $start));
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
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
