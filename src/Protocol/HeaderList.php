<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

final class HeaderList
{
    /**
     * @param array<string> $values
     * @return list<string>
     */
    public static function split(array $values): array
    {
        $parts = [];

        foreach ($values as $value) {
            $current = '';
            $quoted = false;
            $escaped = false;
            $length = strlen($value);

            for ($index = 0; $index < $length; $index++) {
                $character = $value[$index];

                if ($escaped) {
                    $current .= $character;
                    $escaped = false;
                    continue;
                }

                if ($quoted && $character === '\\') {
                    $current .= $character;
                    $escaped = true;
                    continue;
                }

                if ($character === '"') {
                    $quoted = !$quoted;
                    $current .= $character;
                    continue;
                }

                if ($character === ',' && !$quoted) {
                    self::append($parts, $current);
                    $current = '';
                    continue;
                }

                $current .= $character;
            }

            self::append($parts, $current);
        }

        return $parts;
    }

    /**
     * @param list<string> $parts
     */
    private static function append(array &$parts, string $value): void
    {
        $value = trim($value);

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    private function __construct() {}
}
