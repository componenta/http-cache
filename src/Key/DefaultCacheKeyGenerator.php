<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Key;

use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Header;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

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
            'method' => $request->getMethod(),
            'target' => RequestTarget::identity($request),
            'queryParams' => self::validateParameters($request->getQueryParams()),
            'route' => $match?->name,
            'routeParameters' => self::validateParameters($match->parameters ?? []),
            'policy' => $this->policyFingerprint($policy),
            'vary' => $this->varyHeaders($request, $policy),
            'tags' => $this->tagGenerations($tags),
        ];

        if ($policy->private) {
            $payload['cookieParams'] = self::validateParameters($request->getCookieParams());
        }

        return hash('sha256', $this->prefix . "\0" . json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param list<string> $tags
     * @return array<array-key, int>
     */
    private function tagGenerations(array $tags): array
    {
        $versions = $this->tags->versions($tags);
        $ordered = [];
        foreach ($tags as $tag) {
            if (!array_key_exists($tag, $versions)) {
                throw new RuntimeException('HTTP cache tag generation is missing.');
            }
            $ordered[$tag] = $versions[$tag];
        }
        ksort($ordered, SORT_STRING);

        return $ordered;
    }

    /**
     * PHP's parsed request parameters are independent of the wire URI/headers.
     * Reject non-data objects/resources and recursive/excessively deep arrays
     * instead of calling arbitrary serialization code during key generation.
     *
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>
     */
    private static function validateParameters(array $parameters, int $depth = 0): array
    {
        if ($depth >= 32) {
            throw new InvalidArgumentException('HTTP cache request parameters exceed the supported nesting depth.');
        }
        foreach ($parameters as $value) {
            if (is_array($value)) {
                self::validateParameters($value, $depth + 1);
            } elseif ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('HTTP cache request parameters must contain scalar data or arrays.');
            }
        }

        return $parameters;
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
