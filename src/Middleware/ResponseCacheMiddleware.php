<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Middleware;

use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Key\RequestTarget;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Protocol\CacheControl;
use Componenta\Http\Cache\Protocol\EntityTag;
use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\ResponseAge;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Componenta\Http\Header;
use Componenta\Http\HttpMethod;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ResponseCacheMiddleware implements MiddlewareInterface
{
    private const string HEADER_CACHE_STATUS = 'X-Componenta-Cache';

    public function __construct(
        private CachePolicyProviderInterface $policies,
        private CacheKeyGeneratorInterface $keys,
        private ResponseCacheStoreInterface $store,
        private CacheInvalidatorInterface $invalidator,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private bool $debugHeader = false,
        private int $maxEntryBytes = 8_388_608,
    ) {
        if ($maxEntryBytes <= 0) {
            throw new InvalidArgumentException('HTTP cache maximum entry size must be greater than zero.');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isUnsafeMethod($request->getMethod())) {
            return $this->handleUnsafe($request, $handler);
        }

        $onlyIfCached = $this->requestCacheControl($request)->has('only-if-cached');
        $policy = $this->policies->policyFor($request);

        if ($policy === null || !$this->isRequestCacheable($request, $policy)) {
            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS')
                : $handler->handle($request);
        }

        try {
            $key = $this->keys->generate($request, $policy);
        } catch (Throwable) {
            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'BYPASS')
                : $this->withDebugHeader($handler->handle($request), 'BYPASS');
        }

        if (!$this->bypassLookup($request)) {
            try {
                $cached = $this->store->fetch($key);
            } catch (Throwable) {
                $cached = null;
            }

            if ($cached !== null && $this->requestAcceptsCachedResponse($request, $cached, $policy)) {
                try {
                    return $this->cachedResponse($request, $cached);
                } catch (Throwable) {
                    $cached = null;
                }
            }
        }

        if ($onlyIfCached) {
            return $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS');
        }

        $requestTime = microtime(true);
        $response = $handler->handle($request);
        $responseTime = microtime(true);
        $initialAge = ResponseAge::correctedInitialAge($response, $requestTime, $responseTime);
        $ttl = $this->responseTtl($response, $policy, $initialAge, $responseTime);

        if ($ttl === null) {
            return $this->withDebugHeader($response, 'BYPASS');
        }

        $response = $this->prepareResponse($request, $response, $policy);
        $storedResponse = $response->withHeader(Header::AGE, (string) $initialAge);

        try {
            $stored = $this->store->store($key, $storedResponse, $ttl);
        } catch (Throwable) {
            $stored = false;
        }

        return $this->withDebugHeader($response, $stored ? 'MISS' : 'BYPASS');
    }

    private function handleUnsafe(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            return $response;
        }

        try {
            $this->invalidator->invalidateTags([RequestTarget::cacheTag($request)]);
        } catch (Throwable) {
            return $this->withDebugHeader($response, 'INVALIDATION-FAILED');
        }

        return $response;
    }

    private function cachedResponse(ServerRequestInterface $request, CachedResponse $cached): ResponseInterface
    {
        $response = $cached->toResponse($this->responseFactory, $this->streamFactory)
            ->withHeader(Header::AGE, (string) $cached->age(time()));

        if ($this->isNotModified($request, $response, $cached)) {
            $response = $this->notModifiedResponse($response);
        }

        return $this->withDebugHeader($response, 'HIT');
    }

    private function notModifiedResponse(ResponseInterface $cached): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(304);

        foreach ([
            Header::CACHE_CONTROL,
            Header::CONTENT_LOCATION,
            Header::DATE,
            Header::ETAG,
            Header::EXPIRES,
            Header::VARY,
            Header::LAST_MODIFIED,
            Header::AGE,
        ] as $header) {
            if ($cached->hasHeader($header)) {
                $response = $response->withHeader($header, $cached->getHeader($header));
            }
        }

        return $response;
    }

    private function isRequestCacheable(ServerRequestInterface $request, HttpCachePolicy $policy): bool
    {
        if (!$policy->allowsMethod($request->getMethod()) || $request->hasHeader(Header::RANGE)) {
            return false;
        }

        if ($this->requestCacheControl($request)->has('no-store')) {
            return false;
        }

        $hasCredentials = $this->hasCredentials($request);

        return $policy->private ? $hasCredentials : !$hasCredentials;
    }

    private function requestAcceptsCachedResponse(
        ServerRequestInterface $request,
        CachedResponse $cached,
        HttpCachePolicy $policy,
    ): bool {
        $cacheControl = $this->requestCacheControl($request);
        $maxAge = $cacheControl->integer('max-age');
        $minFresh = $cacheControl->integer('min-fresh');

        if ($maxAge === false || $minFresh === false) {
            return false;
        }

        $now = time();

        if (is_int($maxAge) && $cached->age($now) > $maxAge) {
            return false;
        }

        if (is_int($minFresh)) {
            $remainingFreshness = $cached->remainingFreshness($now);

            if ($remainingFreshness === null || $remainingFreshness < $minFresh) {
                return false;
            }
        }

        return $policy->allowsStatus($cached->status);
    }

    private function responseTtl(
        ResponseInterface $response,
        HttpCachePolicy $policy,
        int $currentAge,
        float $responseTime,
    ): ?int {
        if (!$policy->allowsStatus($response->getStatusCode())) {
            return null;
        }

        if ($response->hasHeader(Header::SET_COOKIE) || $response->hasHeader(Header::CONTENT_RANGE)) {
            return null;
        }

        $size = $response->getBody()->getSize();

        if ($size !== null && $size > $this->maxEntryBytes) {
            return null;
        }

        $cacheControl = CacheControl::fromValues($response->getHeader(Header::CACHE_CONTROL));

        if ($cacheControl->has('no-store') || $cacheControl->has('no-cache') || $cacheControl->has('must-understand')) {
            return null;
        }

        if ($cacheControl->has('private') && !$policy->private) {
            return null;
        }

        if (!$this->varyIsCompatible($response, $policy)) {
            return null;
        }

        $ttl = $policy->ttl;
        $freshness = null;

        if (!$policy->private) {
            $freshness = $cacheControl->integer('s-maxage');
        }

        if ($freshness === null) {
            $freshness = $cacheControl->integer('max-age');
        }

        if ($freshness === false) {
            return null;
        }

        if (is_int($freshness)) {
            $remaining = $freshness - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = min($ttl, $remaining);
        } elseif ($response->hasHeader(Header::EXPIRES)) {
            $expires = strtotime($response->getHeaderLine(Header::EXPIRES));
            $date = $response->hasHeader(Header::DATE)
                ? strtotime($response->getHeaderLine(Header::DATE))
                : (int) $responseTime;

            if ($expires === false || $date === false || $expires <= $date) {
                return null;
            }

            $remaining = ($expires - $date) - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = min($ttl, $remaining);
        }

        return max(1, $ttl);
    }

    private function prepareResponse(
        ServerRequestInterface $request,
        ResponseInterface $response,
        HttpCachePolicy $policy,
    ): ResponseInterface {
        if (!$response->hasHeader(Header::CACHE_CONTROL)) {
            $response = $response->withHeader(Header::CACHE_CONTROL, $policy->cacheControlValue());
        } elseif ($policy->private) {
            $response = $this->forcePrivateCacheControl($response);
        }

        $vary = $this->varyHeaders($response);

        foreach ($policy->varyHeaders as $header) {
            $vary[] = $header;
        }

        if ($policy->private && $request->getHeaderLine(Header::AUTHORIZATION) !== '') {
            $vary[] = strtolower(Header::AUTHORIZATION);
        }

        if ($policy->private && $request->getHeaderLine(Header::COOKIE) !== '') {
            $vary[] = strtolower(Header::COOKIE);
        }

        $vary = array_values(array_unique($vary));

        if ($vary !== []) {
            $response = $response->withHeader(Header::VARY, implode(', ', $vary));
        }

        if (
            $policy->generateEtag
            && strtoupper($request->getMethod()) === HttpMethod::GET
            && !$response->hasHeader(Header::ETAG)
        ) {
            $etag = $this->weakEtag($response);

            if ($etag !== null) {
                $response = $response->withHeader(Header::ETAG, $etag);
            }
        }

        return $response;
    }

    private function forcePrivateCacheControl(ResponseInterface $response): ResponseInterface
    {
        $parts = [];

        foreach (HeaderList::split($response->getHeader(Header::CACHE_CONTROL)) as $part) {
            $name = strtolower(trim(explode('=', $part, 2)[0]));

            if (!in_array($name, ['public', 'private'], true)) {
                $parts[] = $part;
            }
        }

        array_unshift($parts, 'private');

        return $response->withHeader(Header::CACHE_CONTROL, implode(', ', $parts));
    }

    private function weakEtag(ResponseInterface $response): ?string
    {
        $body = $response->getBody();

        if (!$body->isSeekable()) {
            return null;
        }

        $context = hash_init('sha256');
        $bytes = 0;
        $body->rewind();

        while (!$body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                break;
            }

            $bytes += strlen($chunk);

            if ($bytes > $this->maxEntryBytes) {
                $body->rewind();

                return null;
            }

            hash_update($context, $chunk);
        }

        $body->rewind();

        return 'W/"' . hash_final($context) . '"';
    }

    private function isNotModified(
        ServerRequestInterface $request,
        ResponseInterface $response,
        CachedResponse $cached,
    ): bool {
        if ($request->hasHeader(Header::IF_NONE_MATCH)) {
            $etag = $response->hasHeader(Header::ETAG) ? $response->getHeaderLine(Header::ETAG) : null;

            return EntityTag::ifNoneMatch($request->getHeader(Header::IF_NONE_MATCH), $etag);
        }

        if (!$request->hasHeader(Header::IF_MODIFIED_SINCE)) {
            return false;
        }

        $condition = strtotime($request->getHeaderLine(Header::IF_MODIFIED_SINCE));

        if ($condition === false) {
            return false;
        }

        if ($response->hasHeader(Header::LAST_MODIFIED)) {
            $validator = strtotime($response->getHeaderLine(Header::LAST_MODIFIED));
        } elseif ($response->hasHeader(Header::DATE)) {
            $validator = strtotime($response->getHeaderLine(Header::DATE));
        } else {
            $validator = $cached->storedAt;
        }

        return $validator !== false && $validator <= $condition;
    }

    private function bypassLookup(ServerRequestInterface $request): bool
    {
        if ($request->hasHeader(Header::IF_MATCH) || $request->hasHeader(Header::IF_UNMODIFIED_SINCE)) {
            return true;
        }

        $cacheControl = $this->requestCacheControl($request);
        $maxAge = $cacheControl->integer('max-age');

        return $cacheControl->has('no-cache')
            || $maxAge === false
            || $maxAge === 0
            || $this->headerHasDirective($request->getHeader(Header::PRAGMA), 'no-cache');
    }

    private function requestCacheControl(ServerRequestInterface $request): CacheControl
    {
        return CacheControl::fromValues($request->getHeader(Header::CACHE_CONTROL));
    }

    private function varyIsCompatible(ResponseInterface $response, HttpCachePolicy $policy): bool
    {
        $vary = $this->varyHeaders($response);

        if (in_array('*', $vary, true)) {
            return false;
        }

        $allowed = $policy->varyHeaders;

        if ($policy->private) {
            $allowed[] = strtolower(Header::AUTHORIZATION);
            $allowed[] = strtolower(Header::COOKIE);
        }

        foreach ($vary as $header) {
            if (!in_array($header, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function varyHeaders(ResponseInterface $response): array
    {
        $headers = [];

        foreach (HeaderList::split($response->getHeader(Header::VARY)) as $header) {
            $header = strtolower(trim($header));

            if ($header !== '' && !in_array($header, $headers, true)) {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    /**
     * @param list<string> $values
     */
    private function headerHasDirective(array $values, string $directive): bool
    {
        foreach (HeaderList::split($values) as $part) {
            if (strtolower(trim(explode('=', $part, 2)[0])) === $directive) {
                return true;
            }
        }

        return false;
    }

    private function hasCredentials(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine(Header::AUTHORIZATION) !== ''
            || $request->getHeaderLine(Header::COOKIE) !== '';
    }

    private function isUnsafeMethod(string $method): bool
    {
        return !HttpMethod::isSafe($method);
    }

    private function withDebugHeader(ResponseInterface $response, string $status): ResponseInterface
    {
        return $this->debugHeader ? $response->withHeader(self::HEADER_CACHE_STATUS, $status) : $response;
    }
}
