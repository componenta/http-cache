<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use Componenta\Http\Cache\Tests\Fixture\InMemoryCache;
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

    public function testEvictedGenerationCannotReviveAnInvalidatedEntry(): void
    {
        $cache = new TagSpyCache();
        $store = new Psr16TagVersionStore($cache);
        $initial = $store->versions(['product:42'])['product:42'];
        self::assertGreaterThan(0, $initial);

        $store->invalidateTags(['product:42']);
        $invalidated = $store->versions(['product:42'])['product:42'];
        self::assertNotSame($initial, $invalidated);

        $cache->storedValue = null;
        $replacement = $store->versions(['product:42'])['product:42'];
        self::assertGreaterThan(0, $replacement);
        self::assertNotSame($initial, $replacement);
        self::assertNotSame($invalidated, $replacement);
        self::assertSame($replacement, $store->versions(['product:42'])['product:42']);
    }

    public function testMissingGenerationWriteFailureIsVisible(): void
    {
        $cache = new TagSpyCache();
        $cache->setResult = false;
        $store = new Psr16TagVersionStore($cache);

        $this->expectException(RuntimeException::class);
        $store->versions(['product:42']);
    }

    public function testBatchedGenerationsAreIndependentAndStable(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16TagVersionStore($cache);
        $first = $store->versions(['catalog', 'product:42', 'catalog']);
        self::assertSame(['catalog', 'product:42'], array_keys($first));
        self::assertSame($first, $store->versions(['product:42', 'catalog']));
        $store->invalidateTags(['product:42']);
        $second = $store->versions(['catalog', 'product:42']);

        self::assertSame($first['catalog'], $second['catalog']);
        self::assertNotSame($first['product:42'], $second['product:42']);
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
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
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
