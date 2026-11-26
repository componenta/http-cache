<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

interface TagVersionStoreInterface extends CacheInvalidatorInterface
{
    /**
     * Return the generation of every requested tag under its requested name.
     * Trim surrounding whitespace consistently with policy tag normalization. Implementations must throw
     * if a generation cannot be obtained safely; missing tags must not be
     * silently omitted. Numeric-string tag names use PHP's integer array keys.
     *
     * @param list<string> $tags
     * @return array<array-key, int>
     */
    public function versions(array $tags): array;
}
