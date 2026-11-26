<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

/** @internal */
final class EntrySize
{
    /**
     * Account for the stored field name, colon/space, value and CRLF.
     *
     * @param array<string, list<string>> $headers
     */
    public static function remainingBodyBudget(array $headers, int $maxBytes): ?int
    {
        $remaining = $maxBytes;
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes = strlen($name) + strlen($value) + 4;
                if ($bytes > $remaining) {
                    return null;
                }
                $remaining -= $bytes;
            }
        }

        return $remaining;
    }

    /** @param array<string, list<string>> $headers */
    public static function fits(array $headers, string $body, int $maxBytes): bool
    {
        $remaining = self::remainingBodyBudget($headers, $maxBytes);

        return $remaining !== null && strlen($body) <= $remaining;
    }

    private function __construct() {}
}
