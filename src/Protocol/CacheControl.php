<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

final readonly class CacheControl
{
    /** @var array<string, list<?string>> */
    private array $directives;

    /**
     * @param array<string> $values
     */
    public static function fromValues(array $values): self
    {
        $directives = [];
        $valid = true;

        foreach (HeaderList::split($values) as $part) {
            $segments = explode('=', $part, 2);
            $name = strtolower(trim($segments[0]));

            if (!self::isToken($name)) {
                $valid = false;
                continue;
            }

            $argument = isset($segments[1]) ? trim($segments[1]) : null;
            if ($argument !== null && !self::isToken($argument) && !self::isQuotedString($argument)) {
                $valid = false;
            }
            $directives[$name][] = $argument;
        }

        return new self($directives, $valid);
    }

    /**
     * @param array<string, list<?string>> $directives
     */
    private function __construct(array $directives, private bool $valid)
    {
        $this->directives = $directives;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    private static function isToken(string $value): bool
    {
        return preg_match("@^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$@D", $value) === 1;
    }

    private static function isQuotedString(string $value): bool
    {
        return preg_match('/\A"(?:[\x09\x20\x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[\x09\x20-\x7E\x80-\xFF])*"\z/D', $value) === 1;
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->directives);
    }

    public function integer(string $name): int|false|null
    {
        $values = $this->directives[strtolower($name)] ?? null;

        if ($values === null) {
            return null;
        }

        if (count($values) !== 1 || $values[0] === null) {
            return false;
        }

        $value = $values[0];

        if (preg_match('/^"([0-9]+)"$/D', $value, $matches) === 1) {
            $value = $matches[1];
        }

        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return false;
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
}
