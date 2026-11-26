<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Componenta\Http\Cache\Internal\CacheableStatus;
use Componenta\Http\Cache\Internal\BodySnapshot;
use Componenta\Http\Cache\Internal\EntrySize;
use Componenta\Http\Cache\Internal\ResponseEligibility;
use Componenta\Http\Cache\Internal\SystemClock;
use Componenta\Http\Cache\Internal\StoredHeaders;
use Componenta\Http\Cache\Protocol\ResponseAge;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

final readonly class Psr16ResponseCacheStore implements ResponseCacheStoreInterface
{
    private const int DEFAULT_MAX_ENTRY_BYTES = 8_388_608;

    public function __construct(
        private CacheInterface $cache,
        private int $maxEntryBytes = self::DEFAULT_MAX_ENTRY_BYTES,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if ($maxEntryBytes <= 0) {
            throw new InvalidArgumentException('HTTP cache maximum entry size must be greater than zero.');
        }
    }

    public function fetch(string $key): ?CachedResponse
    {
        $payload = $this->cache->get($key);

        if (!is_array($payload) || !self::hasStringKeys($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $cached = CachedResponse::fromArray($payload);

        $now = $this->clock->now()->getTimestamp();
        if ($cached === null || $cached->storedAt > $now
            || $cached->freshUntil === null || $cached->freshUntil <= $now) {
            return null;
        }

        if (!EntrySize::fits($cached->headers, $cached->body, $this->maxEntryBytes)) {
            return null;
        }

        return $cached;
    }

    public function store(string $key, ResponseInterface $response, CacheFreshness $freshness): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $status = $response->getStatusCode();

        if (
            $now < $freshness->receivedAt
            || $freshness->remainingAt($now) <= 0
            || !CacheableStatus::allows($status)
            || !ResponseEligibility::hasSafeStorageMetadata($response)
            || $freshness->ageAt($now) < ResponseAge::atReceipt($response, (float) $now)
        ) {
            return false;
        }

        $headers = StoredHeaders::forStorage($response);
        $bodyBudget = EntrySize::remainingBodyBudget($headers, $this->maxEntryBytes);
        if ($bodyBudget === null) {
            return false;
        }

        $contents = BodySnapshot::read($response->getBody(), $bodyBudget);

        if ($contents === null || (CacheableStatus::forbidsContent($status) && $contents !== '')) {
            return false;
        }

        $storedAt = $this->clock->now()->getTimestamp();
        $remaining = $freshness->remainingAt($storedAt);
        if ($storedAt < $now || $remaining <= 0) {
            return false;
        }

        $cached = new CachedResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: $contents,
            storedAt: $storedAt,
            ageAtStore: $freshness->ageAt($storedAt),
            freshUntil: $freshness->freshUntil,
        );

        $payload = $cached->toArray();
        if (CachedResponse::fromArray($payload) === null) {
            return false;
        }

        return $this->cache->set($key, $payload, $remaining);
    }

    public function delete(string $key): void
    {
        if (!$this->cache->delete($key)) {
            throw new RuntimeException('HTTP cache entry could not be removed.');
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function hasStringKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
