<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Middleware;

use Componenta\Http\Cache\Internal\BodySnapshot;
use Componenta\Http\Cache\Internal\ConditionalRequest;
use Componenta\Http\Cache\Internal\ResponseEligibility;
use Componenta\Http\Cache\Internal\ResponseMetadata;
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
                : ResponseMetadata::protectPrivate($handler->handle($request), $policy);
        }

        try {
            $key = $this->keys->generate($request, $policy);
        } catch (Throwable $exception) {
            $this->logger?->warning('HTTP cache key generation failed.', ['exception' => $exception]);

            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'BYPASS')
                : $this->withDebugHeader(ResponseMetadata::protectPrivate($handler->handle($request), $policy), 'BYPASS');
        }

        if (!$this->bypassLookup($request, $cacheControl)) {
            try {
                $cached = $this->store->fetch($key);
            } catch (Throwable $exception) {
                $this->logger?->warning('HTTP cache read failed.', ['exception' => $exception]);
                $cached = null;
            }

            if ($cached !== null && $this->requestAcceptsCachedResponse($request, $cached, $policy, $cacheControl)) {
                try {
                    return ResponseMetadata::protectPrivate($this->cachedResponse($request, $cached), $policy);
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
            $ttl = ResponseEligibility::ttl($response, $policy, $initialAge, $responseTime, $this->maxEntryBytes);

            if ($ttl === null) {
                return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
            }

            $receipt = (int) $responseTime;
            $deadline = $ttl > PHP_INT_MAX - $receipt ? PHP_INT_MAX : $receipt + $ttl;
            $response = ResponseMetadata::prepare($response, $policy, $receipt);
            $storedResponse = $response;

            $generateEtag = $policy->generateEtag && $request->getMethod() === HttpMethod::GET && !$response->hasHeader(Header::ETAG);
            $validateLength = $request->getMethod() === HttpMethod::GET && $response->hasHeader(Header::CONTENT_LENGTH);
            if ($generateEtag || $validateLength) {
                $contents = BodySnapshot::read($response->getBody(), $this->maxEntryBytes);
                if ($contents === null || ($validateLength && !ResponseEligibility::matchesContentLength($response->getHeader(Header::CONTENT_LENGTH), strlen($contents)))) {
                    return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
                }
                if ($generateEtag) {
                    $response = $response->withHeader(Header::ETAG, ResponseMetadata::weakEtag($response, $contents));
                }
                $storedResponse = $response->withBody($this->streamFactory->createStream($contents));
            }

            $now = $this->clock->now()->getTimestamp();
            $remaining = $deadline - $now;
            if ($remaining <= 0) {
                return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
            }
            $elapsed = max(0, $now - $receipt);
            $age = $initialAge > PHP_INT_MAX - $elapsed ? PHP_INT_MAX : $initialAge + $elapsed;
            $storedResponse = $storedResponse->withHeader(Header::AGE, (string) $age);
            $stored = $this->store->store($key, $storedResponse, min($ttl, $remaining));
        } catch (Throwable $exception) {
            $this->logger?->warning('HTTP cache write failed.', ['exception' => $exception]);
            $stored = false;
        }

        return $this->withDebugHeader($stored ? $response : ResponseMetadata::protectPrivate($response, $policy), $stored ? 'MISS' : 'BYPASS');
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
        ServerRequestInterface $request,
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

        $headers = array_change_key_case($cached->headers, CASE_LOWER);
        $responseControl = CacheControl::fromValues($headers['cache-control'] ?? []);
        if ($responseControl->has('no-store') || $responseControl->has('no-cache')
            || $responseControl->has('must-understand') || (!$policy->private && $responseControl->has('private'))
            || isset($headers['set-cookie']) || isset($headers['content-range'])
            || isset($headers['x-accel-redirect']) || isset($headers['x-sendfile'])
            || !ResponseEligibility::varyIsCompatible($headers['vary'] ?? [], $policy)) {
            return false;
        }
        if ($request->getMethod() === HttpMethod::GET
            && !ResponseEligibility::matchesContentLength($headers['content-length'] ?? [], strlen($cached->body))) {
            return false;
        }

        return $policy->allowsStatus($cached->status);
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
