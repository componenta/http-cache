<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

interface CacheInvalidatorInterface
{
    /**
     * Invalidate every distinct tag after trimming surrounding whitespace.
     * Throw on failure; multi-tag operations need not be atomic.
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void;
}
