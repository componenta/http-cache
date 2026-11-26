<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Psr\Http\Message\ResponseInterface;

interface ResponseCacheStoreInterface
{
    /** Return a usable, fresh entry with a valid Date, or null when missing, invalid or expired. */
    public function fetch(string $key): ?CachedResponse;

    /**
     * Retain the response only within the supplied absolute freshness budget.
     * Preserve a valid Date field, or supply the receipt time when missing or invalid.
     * Return false when the response cannot be stored or has expired.
     */
    public function store(string $key, ResponseInterface $response, CacheFreshness $freshness): bool;

    /** Remove the entry, throwing if the backend cannot complete the operation. */
    public function delete(string $key): void;
}
