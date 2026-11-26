<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Middleware;

use Componenta\Http\Cache\Internal\BodySnapshot;
use Componenta\Http\Cache\Internal\ConditionalRequest;
use Componenta\Http\Cache\Internal\SystemClock;
use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Key\RequestTarget;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Protocol\CacheControl;
use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\HttpDate;
use Componenta\Http\Cache\Protocol\ResponseAge;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Componenta\Http\Header;
use Componenta\Http\HttpMethod;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
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
        private ?LoggerInterface $logger = null,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if ($maxEntryBytes <= 0) {
            throw new InvalidArgumentException('HTTP cache maximum entry size must be greater than zero.');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cacheControl = $this->requestCacheControl($request);
        $onlyIfCached = $cacheControl->has('only-if-cached');

        if ($this->isUnsafeMethod($request->getMethod())) {
            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS')
                : $this->handleUnsafe($request, $handler);
        }
        $policy = $this->policies->policyFor($request);

        if ($policy === null || !$policy->enabled || !$this->isRequestCacheable($request, $policy, $cacheControl)) {
            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS')
                : $this->protectPrivateResponse($handler->handle($request), $policy);
        }

        try {
            $key = $this->keys->generate($request, $policy);
        } catch (Throwable $exception) {
            $this->logger?->warning('HTTP cache key generation failed.', ['exception' => $exception]);

            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'BYPASS')
                : $this->withDebugHeader($this->protectPrivateResponse($handler->handle($request), $policy), 'BYPASS');
        }

        if (!$this->bypassLookup($request, $cacheControl)) {
            try {
                $cached = $this->store->fetch($key);
            } catch (Throwable $exception) {
                $this->logger?->warning('HTTP cache read failed.', ['exception' => $exception]);
                $cached = null;
            }

            if ($cached !== null && $this->requestAcceptsCachedResponse($cached, $policy, $cacheControl)) {
                try {
                    return $this->protectPrivateResponse($this->cachedResponse($request, $cached), $policy);
                } catch (Throwable $exception) {
                    $this->logger?->warning('HTTP cached response reconstruction failed.', ['exception' => $exception]);
                    $cached = null;
                }
            }
        }

        if ($onlyIfCached) {
            return $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS');
        }

        $requestTime = (float) $this->clock->now()->format('U.u');
        $response = $handler->handle($request);
        $responseTime = (float) $this->clock->now()->format('U.u');

        if (
            !$response->hasHeader(Header::DATE)
            || HttpDate::parse($response->getHeaderLine(Header::DATE), (int) $responseTime) === null
        ) {
            $response = $response->withHeader(Header::DATE, HttpDate::format((int) $responseTime));
        }

        try {
            $initialAge = ResponseAge::correctedInitialAge($response, $requestTime, $responseTime);
            $ttl = $this->responseTtl($response, $policy, $initialAge, $responseTime);

            if ($ttl === null) {
                return $this->withDebugHeader($this->protectPrivateResponse($response, $policy), 'BYPASS');
            }

            $receipt = (int) $responseTime;
            $deadline = $ttl > PHP_INT_MAX - $receipt ? PHP_INT_MAX : $receipt + $ttl;
            $response = $this->prepareResponse($response, $policy);
            $storedResponse = $response;

            if ($policy->generateEtag && $request->getMethod() === HttpMethod::GET && !$response->hasHeader(Header::ETAG)) {
                $contents = BodySnapshot::read($response->getBody(), $this->maxEntryBytes);
                if ($contents === null) {
                    return $this->withDebugHeader($this->protectPrivateResponse($response, $policy), 'BYPASS');
                }
                $response = $response->withHeader(Header::ETAG, $this->weakEtag($response, $contents));
                $storedResponse = $response->withBody($this->streamFactory->createStream($contents));
            }

            $now = $this->clock->now()->getTimestamp();
            $remaining = $deadline - $now;
            if ($remaining <= 0) {
                return $this->withDebugHeader($this->protectPrivateResponse($response, $policy), 'BYPASS');
            }
            $elapsed = max(0, $now - $receipt);
            $age = $initialAge > PHP_INT_MAX - $elapsed ? PHP_INT_MAX : $initialAge + $elapsed;
            $storedResponse = $storedResponse->withHeader(Header::AGE, (string) $age);
            $stored = $this->store->store($key, $storedResponse, min($ttl, $remaining));
        } catch (Throwable $exception) {
            $this->logger?->warning('HTTP cache write failed.', ['exception' => $exception]);
            $stored = false;
        }

        return $this->withDebugHeader($stored ? $response : $this->protectPrivateResponse($response, $policy), $stored ? 'MISS' : 'BYPASS');
    }

    private function handleUnsafe(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            return $response;
        }

        try {
            $this->invalidator->invalidateTags([RequestTarget::cacheTag($request)]);
        } catch (Throwable $exception) {
            $this->logger?->error('HTTP cache invalidation failed after unsafe request.', ['exception' => $exception]);

            return $this->withDebugHeader($response, 'INVALIDATION-FAILED');
        }

        return $response;
    }

    private function cachedResponse(ServerRequestInterface $request, CachedResponse $cached): ResponseInterface
    {
        $now = $this->clock->now()->getTimestamp();
        $response = ConditionalRequest::isNotModified($request, $cached, $now)
            ? $this->notModifiedResponse($cached)
            : $cached->toResponse($this->responseFactory, $this->streamFactory);

        return $this->withDebugHeader($response->withHeader(Header::AGE, (string) $cached->age($now)), 'HIT');
    }

    private function notModifiedResponse(CachedResponse $cached): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(304);
        $headers = array_change_key_case($cached->headers, CASE_LOWER);

        foreach ([
            Header::CACHE_CONTROL,
            Header::CONTENT_LOCATION,
            Header::DATE,
            Header::ETAG,
            Header::EXPIRES,
            Header::VARY,
            Header::LAST_MODIFIED,
        ] as $header) {
            $values = $headers[strtolower($header)] ?? null;
            if ($values !== null) {
                $response = $response->withHeader($header, $values);
            }
        }

        return $response;
    }

    private function isRequestCacheable(ServerRequestInterface $request, HttpCachePolicy $policy, CacheControl $cacheControl): bool
    {
        if (
            !$policy->allowsMethod($request->getMethod())
            || $request->hasHeader(Header::RANGE)
            || $request->hasHeader(Header::IF_MATCH)
            || $request->hasHeader(Header::IF_UNMODIFIED_SINCE)
        ) {
            return false;
        }

        if ($cacheControl->has('no-store')) {
            return false;
        }

        $hasCredentials = $this->hasCredentials($request);

        return $policy->private ? $hasCredentials : !$hasCredentials;
    }

    private function requestAcceptsCachedResponse(
        CachedResponse $cached,
        HttpCachePolicy $policy,
        CacheControl $cacheControl,
    ): bool {
        $maxAge = $cacheControl->integer('max-age');
        $minFresh = $cacheControl->integer('min-fresh');

        if ($maxAge === false || $minFresh === false) {
            return false;
        }

        $now = $this->clock->now()->getTimestamp();
        $remainingFreshness = $cached->remainingFreshness($now);

        if ($remainingFreshness === null || $remainingFreshness <= 0) {
            return false;
        }

        if (is_int($maxAge) && $cached->age($now) > $maxAge) {
            return false;
        }

        if (is_int($minFresh) && $remainingFreshness < $minFresh) {
            return false;
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

        if (!$this->storageIsPermitted($response, $policy, $cacheControl)) {
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
            $expires = HttpDate::parse($response->getHeaderLine(Header::EXPIRES), (int) $responseTime);
            $date = HttpDate::parse($response->getHeaderLine(Header::DATE), (int) $responseTime);

            if ($expires === null || $date === null || $expires <= $date) {
                return null;
            }

            $remaining = ($expires - $date) - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = min($ttl, $remaining);
        } else {
            $remaining = $policy->ttl - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = $remaining;
        }

        return max(1, $ttl);
    }

    private function storageIsPermitted(
        ResponseInterface $response,
        HttpCachePolicy $policy,
        CacheControl $cacheControl,
    ): bool {
        if (!$response->hasHeader(Header::CACHE_CONTROL) || $policy->private) {
            return true;
        }

        if (
            $cacheControl->has('public')
            || $cacheControl->has('max-age')
            || $response->hasHeader(Header::EXPIRES)
            || $cacheControl->has('s-maxage')
        ) {
            return true;
        }

        return in_array(
            $response->getStatusCode(),
            [200, 203, 204, 300, 301, 308, 404, 405, 410, 414, 501],
            true,
        );
    }

    private function prepareResponse(
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

        if ($policy->private) {
            $vary[] = strtolower(Header::AUTHORIZATION);
            $vary[] = strtolower(Header::COOKIE);
        }

        $vary = array_values(array_unique($vary));

        if ($vary !== []) {
            $response = $response->withHeader(Header::VARY, implode(', ', $vary));
        }

        return $response;
    }

    private function protectPrivateResponse(ResponseInterface $response, ?HttpCachePolicy $policy): ResponseInterface
    {
        // An explicit callback veto is not permission to change the origin response.
        if ($policy === null || !$policy->enabled || !$policy->private) {
            return $response;
        }

        $response = $this->forcePrivateCacheControl($response);
        $vary = $this->varyHeaders($response);
        if (!in_array('*', $vary, true)) {
            $vary[] = strtolower(Header::AUTHORIZATION);
            $vary[] = strtolower(Header::COOKIE);
            $response = $response->withHeader(Header::VARY, implode(', ', array_unique($vary)));
        }

        return $response;
    }

    private function forcePrivateCacheControl(ResponseInterface $response): ResponseInterface
    {
        $parts = [];

        foreach (HeaderList::split($response->getHeader(Header::CACHE_CONTROL)) as $part) {
            $name = strtolower(trim(explode('=', $part, 2)[0]));

            if (!in_array($name, ['public', 'private', 's-maxage'], true)) {
                $parts[] = $part;
            }
        }

        array_unshift($parts, 'private');

        return $response->withHeader(Header::CACHE_CONTROL, implode(', ', $parts));
    }

    private function weakEtag(ResponseInterface $response, string $contents): string
    {
        $context = hash_init('sha256');

        foreach ([
            Header::CONTENT_TYPE,
            Header::CONTENT_ENCODING,
            Header::CONTENT_LANGUAGE,
            Header::CONTENT_LOCATION,
            Header::CONTENT_DIGEST,
        ] as $header) {
            hash_update($context, strtolower($header) . "\0");
            foreach ($response->getHeader($header) as $value) {
                hash_update($context, $value . "\0");
            }
        }

        hash_update($context, $contents);

        return 'W/"' . hash_final($context) . '"';
    }

    private function bypassLookup(ServerRequestInterface $request, CacheControl $cacheControl): bool
    {
        if ($request->hasHeader(Header::IF_MATCH) || $request->hasHeader(Header::IF_UNMODIFIED_SINCE)) {
            return true;
        }

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
     * @param array<string> $values
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
        return !in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }

    private function withDebugHeader(ResponseInterface $response, string $status): ResponseInterface
    {
        return $this->debugHeader ? $response->withHeader(self::HEADER_CACHE_STATUS, $status) : $response;
    }
}
