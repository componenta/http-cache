<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests\Fixture;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

final class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int> */
    private array $expiresAt = [];

    // Without a clock, deliberately ignore backend TTL so tests can verify
    // that the HTTP store independently enforces its absolute deadlines.
    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->expire($key);

        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        unset($this->expiresAt[$key]);
        if ($this->clock !== null && $ttl !== null) {
            $now = $this->clock->now();
            $timestamp = $now->getTimestamp();
            $deadline = $ttl instanceof DateInterval
                ? $now->add($ttl)->getTimestamp()
                : $timestamp + $ttl;
            if ($deadline <= $timestamp) {
                return $this->delete($key);
            }
            $this->expiresAt[$key] = $deadline;
        }
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key], $this->expiresAt[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->expiresAt = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->expire($key);

        return array_key_exists($key, $this->values);
    }

    private function expire(string $key): void
    {
        if ($this->clock !== null && isset($this->expiresAt[$key])
            && $this->clock->now()->getTimestamp() >= $this->expiresAt[$key]) {
            $this->delete($key);
        }
    }
}
