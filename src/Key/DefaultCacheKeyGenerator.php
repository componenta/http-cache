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
        $routeName = $match?->name ?? '_unknown';
        $payload = [
            'method' => strtoupper($request->getMethod()),
            'route' => $routeName,
            'path' => $request->getUri()->getPath(),
            'query' => $this->normalizedQuery($request),
            'vary' => $this->varyHeaders($request, $policy),
            'tags' => $this->tags->versions($policy->tags),
        ];

        return $this->prefix . ':' . hash('xxh128', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedQuery(ServerRequestInterface $request): array
    {
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);

        return $this->sortRecursive($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }

    /**
     * @return array<string, list<string>>
     */
    private function varyHeaders(ServerRequestInterface $request, HttpCachePolicy $policy): array
    {
        $headers = [];

        foreach ($policy->varyHeaders as $header) {
            $headers[strtolower($header)] = $request->getHeader($header);
        }

        if ($policy->allowAuthenticated) {
            $headers[strtolower(Header::AUTHORIZATION)] = $request->getHeader(Header::AUTHORIZATION);
            $headers[strtolower(Header::COOKIE)] = $request->getHeader(Header::COOKIE);
        }

        ksort($headers);

        return $headers;
    }
}
