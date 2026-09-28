<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Key;

use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Header;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DefaultCacheKeyGenerator implements CacheKeyGeneratorInterface
{
    public function __construct(
        private TagVersionStoreInterface $tags,
        private string $prefix = 'http-cache',
    ) {}

    public function generate(ServerRequestInterface $request, HttpCachePolicy $policy): string
    {
        $match = MatchRouteMiddleware::getMatchResultFromRequest($request);
        $tags = $policy->tags;
        $tags[] = RequestTarget::cacheTag($request);
        $tags = array_values(array_unique($tags));
        $payload = [
            'method' => strtoupper($request->getMethod()),
            'target' => RequestTarget::identity($request),
            'route' => $match === null ? '_unknown' : $match->name,
            'policy' => $this->policyFingerprint($policy),
            'vary' => $this->varyHeaders($request, $policy),
            'tags' => $this->tags->versions($tags),
        ];

        return hash('sha256', $this->prefix . "\0" . json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     ttl:int,
     *     methods:list<string>,
     *     statuses:list<int>,
     *     vary:list<string>,
     *     tags:list<string>,
     *     private:bool,
     *     allowAuthenticated:bool,
     *     generateEtag:bool
     * }
     */
    private function policyFingerprint(HttpCachePolicy $policy): array
    {
        return [
            'ttl' => $policy->ttl,
            'methods' => $policy->methods,
            'statuses' => $policy->statuses,
            'vary' => $policy->varyHeaders,
            'tags' => $policy->tags,
            'private' => $policy->private,
            'allowAuthenticated' => $policy->allowAuthenticated,
            'generateEtag' => $policy->generateEtag,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function varyHeaders(ServerRequestInterface $request, HttpCachePolicy $policy): array
    {
        $headers = [];

        foreach ($policy->varyHeaders as $header) {
            $headers[strtolower($header)] = array_values($request->getHeader($header));
        }

        if ($policy->allowAuthenticated) {
            $headers[strtolower(Header::AUTHORIZATION)] = array_values($request->getHeader(Header::AUTHORIZATION));
            $headers[strtolower(Header::COOKIE)] = array_values($request->getHeader(Header::COOKIE));
        }

        ksort($headers);

        return $headers;
    }
}
