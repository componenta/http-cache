<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class Psr16ResponseCacheStore implements ResponseCacheStoreInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function fetch(string $key): ?CachedResponse
    {
        $payload = $this->cache->get($key);

        return is_array($payload) ? CachedResponse::fromArray($payload) : null;
    }

    public function store(string $key, ResponseInterface $response, int $ttl): bool
    {
        $body = $response->getBody();

        if (!$body->isSeekable()) {
            return false;
        }

        $body->rewind();
        $contents = $body->__toString();
        $body->rewind();

        $cached = new CachedResponse(
            status: $response->getStatusCode(),
            headers: $response->getHeaders(),
            body: $contents,
            storedAt: time(),
        );

        return $this->cache->set($key, $cached->toArray(), $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }
}
