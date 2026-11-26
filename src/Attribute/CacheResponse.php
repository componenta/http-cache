<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Attribute;

use Attribute;
use Closure;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use UnexpectedValueException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class CacheResponse
{
    private HttpCachePolicy $staticPolicy;

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
     * @param (Closure(ServerRequestInterface): bool)|null $when
     * @param (Closure(ServerRequestInterface): list<string>)|null $tagsFactory
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
        private ?Closure $when = null,
        private ?Closure $tagsFactory = null,
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

        $this->staticPolicy = $policy;
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

    public function toPolicy(?ServerRequestInterface $request = null): HttpCachePolicy
    {
        if ($this->when === null && $this->tagsFactory === null) {
            return $this->staticPolicy;
        }

        if ($request === null) {
            throw new LogicException('Resolving HTTP cache callbacks requires the current request.');
        }

        $enabled = true;
        $tags = $this->tags;

        if ($this->when !== null) {
            $predicateRequest = $request;
            /** @var mixed $result */
            $result = ($this->when)($predicateRequest);

            if (!is_bool($result)) {
                throw new UnexpectedValueException('HTTP cache "when" callback must return a boolean.');
            }

            $enabled = $result;
        }

        if ($enabled && $this->tagsFactory !== null) {
            $tagsRequest = $request;
            /** @var mixed $dynamicTags */
            $dynamicTags = ($this->tagsFactory)($tagsRequest);

            if (!is_array($dynamicTags) || !array_is_list($dynamicTags)) {
                throw new UnexpectedValueException('HTTP cache "tagsFactory" callback must return a list of non-empty strings.');
            }

            foreach ($dynamicTags as $tag) {
                if (!is_string($tag) || trim($tag) === '') {
                    throw new UnexpectedValueException('HTTP cache "tagsFactory" callback must return a list of non-empty strings.');
                }
                $tags[] = $tag;
            }
        }

        return new HttpCachePolicy(
            ttl: $this->ttl,
            methods: $this->methods,
            statuses: $this->statuses,
            varyHeaders: $this->varyHeaders,
            tags: $tags,
            allowAuthenticated: $this->allowAuthenticated,
            cacheSetCookie: $this->cacheSetCookie,
            private: $this->private,
            generateEtag: $this->generateEtag,
            enabled: $enabled,
        );
    }
}
