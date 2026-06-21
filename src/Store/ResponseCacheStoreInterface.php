<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Psr\Http\Message\ResponseInterface;

interface ResponseCacheStoreInterface
{
    public function fetch(string $key): ?CachedResponse;

    public function store(string $key, ResponseInterface $response, int $ttl): bool;

    public function delete(string $key): void;
}
