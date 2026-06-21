<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

interface TagVersionStoreInterface extends CacheInvalidatorInterface
{
    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    public function versions(array $tags): array;
}
