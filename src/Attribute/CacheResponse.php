<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Attribute;

use Attribute;
use Componenta\Http\Cache\Policy\HttpCachePolicy;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class CacheResponse
{
    private(set) int $ttl;

    /** @var list<string> */
    private(set) array $methods;

    /** @var list<int> */
    private(set) array $statuses;

    /** @var list<string> */
    private(set) array $varyHeaders;

    /** @var list<string> */
    private(set) array $tags;

    private(set) bool $allowAuthenticated;
    private(set) bool $cacheSetCookie;
    private(set) bool $private;
    private(set) bool $generateEtag;

    /**
     * @param list<string> $methods
     * @param list<int> $statuses
     * @param list<string> $varyHeaders
     * @param list<string> $tags
     */
    public function __construct(
        int $ttl,
        array $methods = ['GET', 'HEAD'],
        array $statuses = [200],
        array $varyHeaders = [],
        array $tags = [],
        bool $allowAuthenticated = false,
        bool $cacheSetCookie = false,
        bool $private = false,
        bool $generateEtag = true,
    ) {
        $policy = new HttpCachePolicy(
            ttl: $ttl,
            methods: $methods,
            statuses: $statuses,
            varyHeaders: $varyHeaders,
            tags: $tags,
            allowAuthenticated: $allowAuthenticated,
            cacheSetCookie: $cacheSetCookie,
            private: $private,
            generateEtag: $generateEtag,
        );

        $this->ttl = $policy->ttl;
        $this->methods = $policy->methods;
        $this->statuses = $policy->statuses;
        $this->varyHeaders = $policy->varyHeaders;
        $this->tags = $policy->tags;
        $this->allowAuthenticated = $policy->allowAuthenticated;
        $this->cacheSetCookie = $policy->cacheSetCookie;
        $this->private = $policy->private;
        $this->generateEtag = $policy->generateEtag;
    }

    public function toPolicy(): HttpCachePolicy
    {
        return new HttpCachePolicy(
            ttl: $this->ttl,
            methods: $this->methods,
            statuses: $this->statuses,
            varyHeaders: $this->varyHeaders,
            tags: $this->tags,
            allowAuthenticated: $this->allowAuthenticated,
            cacheSetCookie: $this->cacheSetCookie,
            private: $this->private,
            generateEtag: $this->generateEtag,
        );
    }
}
