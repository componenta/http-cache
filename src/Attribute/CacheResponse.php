<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class CacheResponse
{
    /**
     * @param list<string> $methods
     * @param list<int> $statuses
     * @param list<string> $varyHeaders
     * @param list<string> $tags
     */
    public function __construct(
        private(set) int $ttl,
        private(set) array $methods = ['GET', 'HEAD'],
        private(set) array $statuses = [200],
        private(set) array $varyHeaders = [],
        private(set) array $tags = [],
        private(set) bool $allowAuthenticated = false,
        private(set) bool $cacheSetCookie = false,
        private(set) bool $private = false,
        private(set) bool $generateEtag = true,
    ) {}
}
