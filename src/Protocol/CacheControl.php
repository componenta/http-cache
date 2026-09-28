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

        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1 || strlen($value) > 18) {
            return false;
        }

        $integer = (int) $value;

        if ((string) $integer !== ltrim($value, '0') && !preg_match('/^0+$/D', $value)) {
            return false;
        }

        return $integer;
    }
}
