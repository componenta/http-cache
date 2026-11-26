<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Middleware;

use Componenta\Http\Cache\Internal\BodySnapshot;
use Componenta\Http\Cache\Internal\CacheableStatus;
use Componenta\Http\Cache\Internal\ConditionalRequest;
use Componenta\Http\Cache\Internal\EntrySize;
use Componenta\Http\Cache\Internal\ResponseEligibility;
use Componenta\Http\Cache\Internal\ResponseMetadata;
use Componenta\Http\Cache\Internal\StoredHeaders;
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
use Componenta\Http\Cache\Store\CacheFreshness;
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

        // User policy callbacks are deliberately evaluated outside the cache
        // fallback. Request-body inspection, in contrast, is only an optimization.
        $eligible = false;
        if ($policy !== null && $policy->enabled) {
            try {
                $eligible = $this->isRequestCacheable($request, $policy, $cacheControl);
            } catch (Throwable $exception) {
                $this->reportFailure('HTTP cache request inspection failed.', $exception);
            }
        }

        if ($policy === null || !$policy->enabled || !$eligible) {
            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS')
                : ResponseMetadata::protectPrivate($handler->handle($request), $policy);
        }

        try {
            $key = $this->keys->generate($request, $policy);
        } catch (Throwable $exception) {
            $this->reportFailure('HTTP cache key generation failed.', $exception);

            return $onlyIfCached
                ? $this->withDebugHeader($this->responseFactory->createResponse(504), 'BYPASS')
                : $this->withDebugHeader(ResponseMetadata::protectPrivate($handler->handle($request), $policy), 'BYPASS');
        }

        // A forced refresh or failed read may leave a previous entry behind.
        $mayHaveEntry = true;
        if (!$this->bypassLookup($request, $cacheControl)) {
            try {
                $cached = $this->store->fetch($key);
                $mayHaveEntry = $cached !== null;
            } catch (Throwable $exception) {
                $this->reportFailure('HTTP cache read failed.', $exception);
                $cached = null;
            }

            if ($cached !== null) {
                try {
                    $response = $this->tryCachedResponse($request, $cached, $policy, $cacheControl);
                    if ($response !== null) {
                        return $response;
                    }
                } catch (Throwable $exception) {
                    $this->reportFailure('HTTP cached response reconstruction failed.', $exception);
                    $cached = null;
                }
            }
        }

        if ($onlyIfCached) {
            return $this->withDebugHeader($this->responseFactory->createResponse(504), 'MISS');
        }

        $requestTime = null;
        try {
            $requestTime = (float) $this->clock->now()->format('U.u');
        } catch (Throwable $exception) {
            $this->reportFailure('HTTP cache request timing failed.', $exception);
        }
        // Origin errors must propagate unchanged and origin must run only once.
        $response = $handler->handle($request);

        $stored = false;
        try {
            if ($requestTime === null) {
                return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
            }
            $responseTime = (float) $this->clock->now()->format('U.u');
            if ($responseTime < $requestTime) {
                return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
            }

            if (
                !$response->hasHeader(Header::DATE)
                || HttpDate::parse($response->getHeaderLine(Header::DATE), (int) $responseTime) === null
            ) {
                $response = $response->withHeader(Header::DATE, HttpDate::format((int) $responseTime));
            }

            $initialAge = ResponseAge::correctedInitialAge($response, $requestTime, $responseTime);
            $ttl = ResponseEligibility::ttl($response, $policy, $initialAge, $responseTime, $this->maxEntryBytes);

            if ($ttl === null) {
                return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
            }

            $receipt = (int) $responseTime;
            $freshness = CacheFreshness::fromTtl($receipt, $ttl, $initialAge);
            $snapshot = null;

            $bodyless = $request->getMethod() === HttpMethod::HEAD || CacheableStatus::forbidsContent($response->getStatusCode());
            $generateEtag = !$bodyless && $policy->generateEtag && $request->getMethod() === HttpMethod::GET && !$response->hasHeader(Header::ETAG);
            $validateLength = $request->getMethod() === HttpMethod::GET && $response->hasHeader(Header::CONTENT_LENGTH);
            if ($bodyless || $generateEtag || $validateLength) {
                $contents = BodySnapshot::read($response->getBody(), $bodyless ? 0 : $this->maxEntryBytes);
                if ($contents === null || ($bodyless && $contents !== '') || ($validateLength && !ResponseEligibility::matchesContentLength($response->getHeader(Header::CONTENT_LENGTH), strlen($contents)))) {
                    return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
                }
                if ($generateEtag) {
                    $response = $response->withHeader(Header::ETAG, ResponseMetadata::weakEtag($response, $contents));
                }
                $snapshot = $this->streamFactory->createStream($contents);
            }

            $now = $this->clock->now()->getTimestamp();
            if ($now < $freshness->receivedAt || $freshness->remainingAt($now) <= 0) {
                return $this->withDebugHeader(ResponseMetadata::protectPrivate($response, $policy), 'BYPASS');
            }
            // Stage cache-specific metadata without modifying the response that
            // will be returned when admission or storage fails.
            $prepared = ResponseMetadata::prepare($response, $policy, $receipt)
                ->withHeader(Header::AGE, (string) $freshness->ageAt($now));
            $storedResponse = $snapshot === null ? $prepared : $prepared->withBody($snapshot);
            $stored = $this->store->store($key, $storedResponse, $freshness);
            if ($stored) {
                $response = $prepared;
                // Account for time spent waiting for the storage adapter too.
                // Keep the prepared response if this final clock read fails.
                $response = $response->withHeader(
                    Header::AGE,
                    (string) $freshness->ageAt(max($now, $this->clock->now()->getTimestamp())),
                );
            }
        } catch (Throwable $exception) {
            $this->reportFailure('HTTP cache write failed.', $exception);
        } finally {
            // A new origin response supersedes an earlier response for this key,
            // even if the new one cannot be retained. Do not refresh on a 304
            // without implementing the validator/metadata merge contract.
            if (!$stored && $mayHaveEntry) {
                try {
                    $this->store->delete($key);
                } catch (Throwable $exception) {
                    $this->reportFailure('HTTP cache superseded entry removal failed.', $exception, error: true);
                }
            }
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
            $this->reportFailure('HTTP cache invalidation failed after unsafe request.', $exception, error: true);

            return $this->withDebugHeader($response, 'INVALIDATION-FAILED');
        }

        return $response;
    }

    private function tryCachedResponse(
        ServerRequestInterface $request,
        CachedResponse $cached,
        HttpCachePolicy $policy,
        CacheControl $cacheControl,
    ): ?ResponseInterface {
        $selectedAt = $this->clock->now()->getTimestamp();
        if (!$this->requestAcceptsCachedResponse($request, $cached, $policy, $cacheControl, $selectedAt)) {
            return null;
        }

        $response = ConditionalRequest::isNotModified($request, $cached, $selectedAt)
            ? $this->notModifiedResponse($cached)
            : $cached->toResponse($this->responseFactory, $this->streamFactory);
        $response = ResponseMetadata::protectPrivate($response, $policy);

        // Factories and streams may perform work or suspend. Do not publish an
        // expired hit or an age measured before response reconstruction.
        $now = $this->clock->now()->getTimestamp();
        if ($now < $selectedAt || ($now !== $selectedAt
            && !$this->requestAcceptsCachedResponse($request, $cached, $policy, $cacheControl, $now))) {
            return null;
        }

        return $this->withDebugHeader($response->withHeader(Header::AGE, (string) $cached->age($now)), 'HIT');
    }

    private function notModifiedResponse(CachedResponse $cached): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(304);
        foreach ($response->getHeaders() as $name => $_) {
            $response = $response->withoutHeader((string) $name);
        }
        if ($response->getBody()->getSize() !== 0) {
            $response = $response->withBody($this->streamFactory->createStream(''));
        }
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
            || $request->hasHeader(Header::TRANSFER_ENCODING)
            || !ResponseEligibility::matchesContentLength($request->getHeader(Header::CONTENT_LENGTH), 0)
            || $request->getBody()->getSize() !== 0
            || $request->getUploadedFiles() !== []
            || ($request->getParsedBody() !== null && $request->getParsedBody() !== [])
        ) {
            return false;
        }

        if (!$cacheControl->isValid() || $cacheControl->has('no-store')) {
            return false;
        }

        $hasCredentials = $this->hasCredentials($request);

        return $policy->private
            ? $hasCredentials
            : !$hasCredentials && !$request->hasHeader(Header::AUTHORIZATION) && !$request->hasHeader(Header::COOKIE);
    }

    private function requestAcceptsCachedResponse(
        ServerRequestInterface $request,
        CachedResponse $cached,
        HttpCachePolicy $policy,
        CacheControl $cacheControl,
        int $now,
    ): bool {
        $maxAge = $cacheControl->integer('max-age');
        $minFresh = $cacheControl->integer('min-fresh');

        if ($maxAge === false || $minFresh === false) {
            return false;
        }

        if ($cached->storedAt < 0 || $cached->storedAt > $now || $cached->ageAtStore < 0
            || !CacheableStatus::allows($cached->status)
            || !EntrySize::fits($cached->headers, $cached->body, $this->maxEntryBytes)) {
            return false;
        }

        $remainingFreshness = $cached->remainingFreshness($now);

        if ($remainingFreshness === null || $remainingFreshness <= 0) {
            return false;
        }

        // The policy bounds the local retention interval, even if a custom
        // store supplies an excessively distant deadline.
        $remainingFreshness = min($remainingFreshness, $policy->ttl - ($now - $cached->storedAt));
        if ($remainingFreshness <= 0) {
            return false;
        }

        $age = $cached->age($now);
        if (is_int($maxAge) && $age > $maxAge) {
            return false;
        }

        $headers = StoredHeaders::normalize($cached->headers);
        if ($headers === null) {
            return false;
        }
        $responseControl = CacheControl::fromValues($headers['cache-control'] ?? []);
        if (!$responseControl->isValid() || $responseControl->has('no-store') || $responseControl->has('no-cache')
            || $responseControl->has('must-understand') || (!$policy->private && $responseControl->has('private'))
            || !ResponseEligibility::varyIsCompatible($headers['vary'] ?? [], $policy)) {
            return false;
        }
        // Do not trust a backend's deadline alone: a corrupted or custom
        // store must not extend the origin's explicit freshness directives.
        $originFreshness = $policy->private ? null : $responseControl->integer('s-maxage');
        $originFreshness ??= $responseControl->integer('max-age');
        if ($originFreshness === false) {
            return false;
        }
        if ($originFreshness === null && isset($headers['expires'])) {
            $expires = HttpDate::parse(implode(', ', $headers['expires']), $now);
            $date = isset($headers['date']) ? HttpDate::parse(implode(', ', $headers['date']), $now) : null;
            if ($expires === null || $date === null || $expires <= $date) {
                return false;
            }
            $originFreshness = $expires - $date;
        }
        // Without an explicit freshness lifetime, admission uses the policy
        // TTL minus the representation's total age, not just its local age.
        $remainingFreshness = min($remainingFreshness, ($originFreshness ?? $policy->ttl) - $age);
        if ($remainingFreshness <= 0 || (is_int($minFresh) && $remainingFreshness < $minFresh)) {
            return false;
        }

        if (($request->getMethod() === HttpMethod::HEAD || CacheableStatus::forbidsContent($cached->status)) && $cached->body !== '') {
            return false;
        }
        if (!ResponseEligibility::validContentFraming($cached->status, $headers['content-length'] ?? [])) {
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
            || $request->getHeaderLine(Header::COOKIE) !== ''
            || $request->getCookieParams() !== [];
    }

    private function isUnsafeMethod(string $method): bool
    {
        return !in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }

    private function reportFailure(string $message, Throwable $exception, bool $error = false): void
    {
        try {
            if ($error) {
                $this->logger?->error($message, ['exception' => $exception]);
            } else {
                $this->logger?->warning($message, ['exception' => $exception]);
            }
        } catch (Throwable) {
            // Diagnostics must not replace the origin response or mask a
            // completed unsafe request when the logging backend also fails.
        }
    }

    private function withDebugHeader(ResponseInterface $response, string $status): ResponseInterface
    {
        return $this->debugHeader ? $response->withHeader(self::HEADER_CACHE_STATUS, $status) : $response;
    }
}
