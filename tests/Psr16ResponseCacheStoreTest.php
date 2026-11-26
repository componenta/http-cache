<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Store\Psr16ResponseCacheStore;
use Componenta\Http\Cache\Tests\Fixture\InMemoryCache;
use Componenta\Http\Cache\Tests\Fixture\MutableClock;
use DateTimeImmutable;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class Psr16ResponseCacheStoreTest extends TestCase
{
    public function testRejectsBodyLargerThanConfiguredLimit(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache, maxEntryBytes: 4);

        self::assertFalse($store->store('key', new Response(200, [], '12345'), 60));
        self::assertSame([], $cache->values);
    }

    public function testStorePreservesOriginalBodyPosition(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);
        $response = new Response(200, [], 'abcdef');
        $response->getBody()->seek(3);

        self::assertTrue($store->store('key', $response, 60));
        self::assertSame(3, $response->getBody()->tell());

        $cached = $store->fetch('key');
        self::assertNotNull($cached);
        self::assertSame('abcdef', $cached->body);
    }

    public function testRejectsEmptyReadBeforeEofAndRestoresPosition(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn(null);
        $body->method('tell')->willReturn(2);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willReturnOnConsecutiveCalls('partial', '');
        $body->expects(self::once())->method('seek')->with(2);

        self::assertFalse($store->store('key', (new Response(200))->withBody($body), 60));
        self::assertSame([], $cache->values);
    }

    public function testRejectsUnsupportedProtocolStatuses(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);

        self::assertFalse($store->store('informational', new Response(101), 60));
        self::assertFalse($store->store('partial', new Response(206), 60));
        self::assertFalse($store->store('not-modified', new Response(304), 60));
        foreach ([428, 429, 431, 511] as $status) {
            self::assertFalse($store->store('forbidden', new Response($status, ['Cache-Control' => 'public, max-age=60']), 60));
        }
        self::assertFalse($store->store('invalid-204', new Response(204, [], 'body'), 60));
        self::assertFalse($store->store('invalid-205', new Response(205, [], 'body'), 60));
        self::assertSame([], $cache->values);
    }

    public function testDoesNotStoreSetCookieResponse(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);

        self::assertFalse($store->store('key', new Response(200, ['Set-Cookie' => 'session=abc'], 'body'), 60));
        self::assertSame([], $cache->values);
    }

    public function testRemovesConnectionSpecificHeadersBeforeStorage(): void
    {
        $cache = new InMemoryCache();
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

    public function testCannotStripConnectionNominatedCacheMetadata(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);
        $response = new Response(200, ['Connection' => 'Vary', 'Vary' => 'Accept-Language'], 'body');

        self::assertFalse($store->store('key', $response, 60));
        self::assertSame([], $cache->values);
    }

    public function testPreservesUpstreamAgeWhenServingCachedResponse(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);
        $response = new Response(200, ['Age' => '120'], 'body');

        self::assertTrue($store->store('key', $response, 60));

        $cached = $store->fetch('key');
        self::assertNotNull($cached);
        self::assertGreaterThanOrEqual(120, $cached->age(time()));
    }

    public function testRejectsPoisonedCachedHeaderMetadata(): void
    {
        $cache = new InMemoryCache();
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

    public function testRejectsSemanticallyInvalidCachedPayloads(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);
        $valid = [
            'status' => 200,
            'headers' => [],
            'body' => 'body',
            'storedAt' => time(),
            'ageAtStore' => 0,
            'freshUntil' => time() + 60,
        ];

        foreach ([
            ['status' => 206],
            ['status' => 304],
            ['status' => 428],
            ['status' => 429],
            ['status' => 431],
            ['status' => 511],
            ['status' => 204],
            ['status' => 205],
            ['headers' => ['Set-Cookie' => ['session=secret']]],
            ['headers' => ['Content-Range' => ['bytes 0-1/4']]],
            ['headers' => ['Cache-Control' => ['no-store']]],
            ['headers' => ['Cache-Control' => ['no-cache']]],
            ['headers' => ['Vary' => ['*']]],
            ['headers' => ['Connection' => ['X-Secret']]],
            ['headers' => ['X-Test' => ['one'], 'x-test' => ['two']]],
            ['headers' => ['X-Test' => ["bad\0value"]]],
            ['storedAt' => time() + 30, 'freshUntil' => time() + 60],
            ['freshUntil' => 0],
        ] as $invalid) {
            $cache->values['key'] = array_replace($valid, $invalid);
            self::assertNull($store->fetch('key'));
        }
    }

    public function testStoreRejectsNoStoreAndWildcardVary(): void
    {
        $cache = new InMemoryCache();
        $store = new Psr16ResponseCacheStore($cache);

        self::assertFalse($store->store('key', new Response(200, ['Cache-Control' => 'no-store'], 'body'), 60));
        self::assertFalse($store->store('key', new Response(200, ['Vary' => '*'], 'body'), 60));
        self::assertSame([], $cache->values);
    }

    public function testRejectsOversizedCachedPayloadOnRead(): void
    {
        $cache = new InMemoryCache();
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

    public function testSnapshotTimeDoesNotExtendFreshnessDeadline(): void
    {
        $cache = new InMemoryCache();
        $clock = new MutableClock(new DateTimeImmutable('@100'));
        $store = new Psr16ResponseCacheStore($cache, clock: $clock);
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn(4);
        $body->method('tell')->willReturn(0);
        $body->method('eof')->willReturn(true);
        $body->method('read')->willReturnCallback(static function () use ($clock): string {
            $clock->advance(1);
            return 'body';
        });

        self::assertFalse($store->store('expired', (new Response(200))->withBody($body), 1));
        self::assertNull($store->fetch('expired'));
    }

    public function testPersistsFreshnessDeadlineFromStorageTtl(): void
    {
        $cache = new InMemoryCache();
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
