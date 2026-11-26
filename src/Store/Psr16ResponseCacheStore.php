<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Componenta\Http\Cache\Internal\CacheableStatus;
use Componenta\Http\Cache\Internal\BodySnapshot;
use Componenta\Http\Cache\Internal\ResponseEligibility;
use Componenta\Http\Cache\Internal\SystemClock;
use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Header;
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

        if (strlen($cached->body) > $this->maxEntryBytes - $this->headerBytes($cached->headers)) {
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
        ) {
            return false;
        }

        $headers = $this->storableHeaders($response);
        $headerBytes = $this->headerBytes($headers);

        if ($headerBytes > $this->maxEntryBytes) {
            return false;
        }

        $contents = BodySnapshot::read($response->getBody(), $this->maxEntryBytes - $headerBytes);

        if ($contents === null || (CacheableStatus::forbidsContent($status) && $contents !== '')) {
            return false;
        }

        $storedAt = $this->clock->now()->getTimestamp();
        $remaining = $freshness->remainingAt($storedAt);
        if ($storedAt < $freshness->receivedAt || $remaining <= 0) {
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
     * @return array<string, list<string>>
     */
    private function storableHeaders(ResponseInterface $response): array
    {
        $blocked = [
            strtolower(Header::CONNECTION) => true,
            strtolower(Header::KEEP_ALIVE) => true,
            strtolower(Header::PROXY_AUTHENTICATE) => true,
            'proxy-authentication-info' => true,
            strtolower(Header::PROXY_AUTHORIZATION) => true,
            'proxy-connection' => true,
            strtolower(Header::TE) => true,
            strtolower(Header::TRAILER) => true,
            strtolower(Header::TRANSFER_ENCODING) => true,
            strtolower(Header::UPGRADE) => true,
        ];

        foreach (HeaderList::split(array_values($response->getHeader(Header::CONNECTION))) as $name) {
            $blocked[strtolower($name)] = true;
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (!is_string($name) || isset($blocked[strtolower($name)])) {
                continue;
            }

            $headers[$name] = array_values($values);
        }

        return $headers;
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

    /**
     * @param array<string, list<string>> $headers
     */
    private function headerBytes(array $headers): int
    {
        $bytes = 0;

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + strlen($value) + 4;

                if ($bytes > $this->maxEntryBytes) {
                    return $bytes;
                }
            }
        }

        return $bytes;
    }
}
