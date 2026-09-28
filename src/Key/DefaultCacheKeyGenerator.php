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
        $uri = $request->getUri();
        $payload = [
            'method' => strtoupper($request->getMethod()),
            'scheme' => strtolower($uri->getScheme()),
            'host' => strtolower($uri->getHost()),
            'port' => $uri->getPort(),
            'route' => $match?->name ?? '_unknown',
            'path' => $uri->getPath(),
            'query' => $uri->getQuery(),
            'vary' => $this->varyHeaders($request, $policy),
            'tags' => $this->tags->versions($policy->tags),
        ];

        return hash('sha256', $this->prefix . "\0" . json_encode($payload, JSON_THROW_ON_ERROR));
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
