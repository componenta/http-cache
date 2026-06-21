<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

interface CacheInvalidatorInterface
{
    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void;
}
