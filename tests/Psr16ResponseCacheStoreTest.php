<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Store\Psr16ResponseCacheStore;
use DateInterval;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class Psr16ResponseCacheStoreTest extends TestCase
{
    public function testRejectsBodyLargerThanConfiguredLimit(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache, maxEntryBytes: 4);

        self::assertFalse($store->store('key', new Response(200, [], '12345'), 60));
        self::assertSame([], $cache->values);
    }

    public function testStorePreservesOriginalBodyPosition(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache);
        $response = new Response(200, [], 'abcdef');
        $response->getBody()->seek(3);

        self::assertTrue($store->store('key', $response, 60));
        self::assertSame(3, $response->getBody()->tell());

        $cached = $store->fetch('key');
        self::assertNotNull($cached);
        self::assertSame('abcdef', $cached->body);
    }

    public function testRejectsUnsupportedProtocolStatuses(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache);

        self::assertFalse($store->store('informational', new Response(101), 60));
        self::assertFalse($store->store('partial', new Response(206), 60));
        self::assertFalse($store->store('not-modified', new Response(304), 60));
        self::assertSame([], $cache->values);
    }

    public function testDoesNotStoreSetCookieResponse(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache);

        self::assertFalse($store->store('key', new Response(200, ['Set-Cookie' => 'session=abc'], 'body'), 60));
        self::assertSame([], $cache->values);
    }

    public function testRemovesConnectionSpecificHeadersBeforeStorage(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache);
        $response = new Response(200, [
            'Connection' => 'X-Trace',
            'X-Trace' => 'secret',
            'Keep-Alive' => 'timeout=5',
            'Proxy-Authentication-Info' => 'secret',
            'X-End-To-End' => 'kept',
        ], 'body');

        self::assertTrue($store->store('key', $response, 60));

        $cached = $store->fetch('key');
        self::assertNotNull($cached);
        self::assertArrayNotHasKey('Connection', $cached->headers);
        self::assertArrayNotHasKey('X-Trace', $cached->headers);
        self::assertArrayNotHasKey('Keep-Alive', $cached->headers);
        self::assertArrayNotHasKey('Proxy-Authentication-Info', $cached->headers);
        self::assertSame(['kept'], $cached->headers['X-End-To-End']);
    }

    public function testPreservesUpstreamAgeWhenServingCachedResponse(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache);
        $response = new Response(200, ['Age' => '120'], 'body');

        self::assertTrue($store->store('key', $response, 60));

        $cached = $store->fetch('key');
        self::assertNotNull($cached);
        self::assertGreaterThanOrEqual(120, $cached->age(time()));
    }

    public function testRejectsPoisonedCachedHeaderMetadata(): void
    {
        $cache = new ArrayCache();
        $cache->values['key'] = [
            'status' => 200,
            'headers' => ["X-Test\r\nInjected" => ['value']],
            'body' => 'body',
            'storedAt' => time(),
            'ageAtStore' => 0,
            'freshUntil' => time() + 60,
        ];
        $store = new Psr16ResponseCacheStore($cache);

        self::assertNull($store->fetch('key'));
    }

    public function testRejectsOversizedCachedPayloadOnRead(): void
    {
        $cache = new ArrayCache();
        $cache->values['key'] = [
            'status' => 200,
            'headers' => [],
            'body' => '12345',
            'storedAt' => time(),
            'ageAtStore' => 0,
            'freshUntil' => time() + 60,
        ];
        $store = new Psr16ResponseCacheStore($cache, maxEntryBytes: 4);

        self::assertNull($store->fetch('key'));
    }

    public function testCachedAgeSaturatesInsteadOfOverflowing(): void
    {
        $cached = new \Componenta\Http\Cache\Store\CachedResponse(
            status: 200,
            headers: [],
            body: 'body',
            storedAt: 0,
            ageAtStore: PHP_INT_MAX,
            freshUntil: PHP_INT_MAX,
        );

        self::assertSame(PHP_INT_MAX, $cached->age(time()));
    }

    public function testPersistsFreshnessDeadlineFromStorageTtl(): void
    {
        $cache = new ArrayCache();
        $store = new Psr16ResponseCacheStore($cache);

        self::assertTrue($store->store('key', new Response(200, [], 'body'), 60));

        $cached = $store->fetch('key');
        self::assertNotNull($cached);
        $remaining = $cached->remainingFreshness(time());
        self::assertNotNull($remaining);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }
}

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

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

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
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
        return array_key_exists($key, $this->values);
    }
}
