<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

final readonly class CacheControl
{
    /** @var array<string, list<?string>> */
    private array $directives;

    /**
     * @param list<string> $values
     */
    public static function fromValues(array $values): self
    {
        $directives = [];

        foreach (HeaderList::split($values) as $part) {
            [$name, $argument] = array_pad(explode('=', $part, 2), 2, null);
            $name = strtolower(trim($name));

            if ($name === '') {
                continue;
            }

            $directives[$name][] = $argument === null ? null : trim($argument);
        }

        return new self($directives);
    }

    /**
     * @param array<string, list<?string>> $directives
     */
    private function __construct(array $directives)
    {
        $this->directives = $directives;
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
