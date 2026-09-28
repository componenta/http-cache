<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

final class Psr16TagVersionStoreTest extends TestCase
{
    public function testUsesPortablePsr16Keys(): void
    {
        $cache = new TagSpyCache();
        $store = new Psr16TagVersionStore($cache, 'prefix:with:reserved:characters');

        $store->versions(['product:42']);

        self::assertNotNull($cache->lastKey);
        self::assertSame(64, strlen($cache->lastKey));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $cache->lastKey);
    }

    public function testInvalidationFailureIsNotSilentlyIgnored(): void
    {
        $cache = new TagSpyCache();
        $cache->setResult = false;
        $store = new Psr16TagVersionStore($cache);

        $this->expectException(RuntimeException::class);

        $store->invalidateTags(['product:42']);
    }

    public function testCorruptedGenerationIsReplacedInsteadOfFallingBackToZero(): void
    {
        $cache = new TagSpyCache();
        $cache->storedValue = 'corrupted';
        $store = new Psr16TagVersionStore($cache);

        $versions = $store->versions(['product:42']);

        self::assertGreaterThan(0, $versions['product:42']);
        self::assertSame($versions['product:42'], $cache->storedValue);
    }

    public function testPersistedZeroGenerationIsReplaced(): void
    {
        $cache = new TagSpyCache();
        $cache->storedValue = 0;
        $store = new Psr16TagVersionStore($cache);

        $versions = $store->versions(['product:42']);

        self::assertGreaterThan(0, $versions['product:42']);
        self::assertSame($versions['product:42'], $cache->storedValue);
    }

    public function testCorruptedGenerationRepairFailureIsVisible(): void
    {
        $cache = new TagSpyCache();
        $cache->storedValue = 'corrupted';
        $cache->setResult = false;
        $store = new Psr16TagVersionStore($cache);

        $this->expectException(RuntimeException::class);

        $store->versions(['product:42']);
    }
}

final class TagSpyCache implements CacheInterface
{
    public ?string $lastKey = null;
    public bool $setResult = true;
    public mixed $storedValue = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->lastKey = $key;

        return $this->storedValue ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->lastKey = $key;

        if ($this->setResult) {
            $this->storedValue = $value;
        }

        return $this->setResult;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        $this->storedValue = null;

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return $this->storedValue !== null;
    }
}
