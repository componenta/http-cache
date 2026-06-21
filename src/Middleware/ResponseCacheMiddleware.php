<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Middleware;

use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Componenta\Http\Header;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ResponseCacheMiddleware implements MiddlewareInterface
{
    private const string HEADER_CACHE_STATUS = 'X-Componenta-Cache';

    public function __construct(
        private CachePolicyProviderInterface $policies,
        private CacheKeyGeneratorInterface $keys,
        private ResponseCacheStoreInterface $store,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private bool $debugHeader = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $policy = $this->policies->policyFor($request);

        if ($policy === null || !$this->isRequestCacheable($request, $policy)) {
            return $handler->handle($request);
        }

        $key = $this->keys->generate($request, $policy);

        if (!$this->hasRequestDirective($request, 'no-cache')) {
            $cached = $this->store->fetch($key);

            if ($cached !== null) {
                return $this->cachedResponse($request, $cached);
            }
        }

        $response = $handler->handle($request);

        if (!$this->isResponseCacheable($response, $policy)) {
            return $this->withDebugHeader($response, 'BYPASS');
        }

        $response = $this->prepareResponse($response, $policy);
        $stored = $this->store->store($key, $response, $policy->ttl);

        return $this->withDebugHeader($response, $stored ? 'MISS' : 'BYPASS');
    }

    private function cachedResponse(ServerRequestInterface $request, CachedResponse $cached): ResponseInterface
    {
        $response = $cached->toResponse($this->responseFactory, $this->streamFactory)
            ->withHeader(Header::AGE, (string) $cached->age(time()));

        if ($this->etagMatches($request, $response)) {
            $cachedResponse = $response;
            $response = $this->responseFactory->createResponse(304)
                ->withHeader(Header::ETAG, $cachedResponse->getHeader(Header::ETAG))
                ->withHeader(Header::CACHE_CONTROL, $cachedResponse->getHeader(Header::CACHE_CONTROL))
                ->withHeader(Header::AGE, $cachedResponse->getHeader(Header::AGE));

            if ($cachedResponse->hasHeader(Header::VARY)) {
                $response = $response->withHeader(Header::VARY, $cachedResponse->getHeader(Header::VARY));
            }
        }

        return $this->withDebugHeader($response, 'HIT');
    }

    private function isRequestCacheable(ServerRequestInterface $request, HttpCachePolicy $policy): bool
    {
        if (!$policy->allowsMethod($request->getMethod())) {
            return false;
        }

        if ($this->hasRequestDirective($request, 'no-store')) {
            return false;
        }

        return $policy->allowAuthenticated
            || (!$request->hasHeader(Header::AUTHORIZATION) && !$request->hasHeader(Header::COOKIE));
    }

    private function isResponseCacheable(ResponseInterface $response, HttpCachePolicy $policy): bool
    {
        if (!$policy->allowsStatus($response->getStatusCode())) {
            return false;
        }

        if (!$policy->cacheSetCookie && $response->hasHeader(Header::SET_COOKIE)) {
            return false;
        }

        return !$this->hasResponseDirective($response, 'no-store')
            && ($policy->private || !$this->hasResponseDirective($response, 'private'));
    }

    private function prepareResponse(ResponseInterface $response, HttpCachePolicy $policy): ResponseInterface
    {
        $response = $response->withHeader(Header::CACHE_CONTROL, $policy->cacheControlValue());

        if ($policy->varyHeaders !== []) {
            $response = $response->withHeader(Header::VARY, implode(', ', $policy->varyHeaders));
        }

        if ($policy->generateEtag && !$response->hasHeader(Header::ETAG)) {
            $etag = $this->weakEtag($response);

            if ($etag !== null) {
                $response = $response->withHeader(Header::ETAG, $etag);
            }
        }

        return $response;
    }

    private function weakEtag(ResponseInterface $response): ?string
    {
        $body = $response->getBody();

        if (!$body->isSeekable()) {
            return null;
        }

        $body->rewind();
        $contents = $body->__toString();
        $body->rewind();

        return 'W/' . chr(34) . hash('xxh128', $contents) . chr(34);
    }

    private function etagMatches(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        if (!$request->hasHeader(Header::IF_NONE_MATCH) || !$response->hasHeader(Header::ETAG)) {
            return false;
        }

        $etag = $response->getHeaderLine(Header::ETAG);

        foreach ($request->getHeader(Header::IF_NONE_MATCH) as $value) {
            foreach (array_map('trim', explode(',', $value)) as $candidate) {
                if ($candidate === '*' || $candidate === $etag) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasRequestDirective(ServerRequestInterface $request, string $directive): bool
    {
        return $this->hasDirective($request->getHeader(Header::CACHE_CONTROL), $directive)
            || ($directive === 'no-cache' && $this->hasDirective($request->getHeader(Header::PRAGMA), 'no-cache'));
    }

    private function hasResponseDirective(ResponseInterface $response, string $directive): bool
    {
        return $this->hasDirective($response->getHeader(Header::CACHE_CONTROL), $directive);
    }

    /**
     * @param list<string> $values
     */
    private function hasDirective(array $values, string $directive): bool
    {
        foreach ($values as $value) {
            foreach (explode(',', strtolower($value)) as $part) {
                if (trim(explode('=', $part, 2)[0]) === $directive) {
                    return true;
                }
            }
        }

        return false;
    }

    private function withDebugHeader(ResponseInterface $response, string $status): ResponseInterface
    {
        return $this->debugHeader ? $response->withHeader(self::HEADER_CACHE_STATUS, $status) : $response;
    }
}
