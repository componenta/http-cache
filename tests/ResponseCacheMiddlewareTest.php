<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Key\RequestTarget;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Protocol\HttpDate;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ResponseCacheMiddlewareTest extends TestCase
{
    public function testUnsafeRequestAlwaysReachesOriginAndInvalidatesTargetUri(): void
    {
        $request = new ServerRequest('PUT', 'https://example.test/items/42');
        $policies = $this->createMock(CachePolicyProviderInterface::class);
        $policies->expects(self::never())->method('policyFor');
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $invalidator = $this->createMock(CacheInvalidatorInterface::class);
        $invalidator->expects(self::once())
            ->method('invalidateTags')
            ->with([RequestTarget::cacheTag($request)]);
        $handler = $this->handler(new Response(204));

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testUndeclaredResponseVaryIsNotCached(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/articles');
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, ['Vary' => 'Accept-Language'], 'hello'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame('Accept-Language', $response->getHeaderLine('Vary'));
    }

    public function testAuthenticatedCachedResponseIsForcedPrivateAndVariesByCredentials(): void
    {
        $request = (new ServerRequest('GET', 'https://example.test/account'))
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('Cookie', 'session=abc');
        $policy = new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::once())
            ->method('store')
            ->willReturnCallback(static function (string $key, ResponseInterface $response, int $ttl): bool {
                self::assertSame('cache-key', $key);
                self::assertSame(60, $ttl);
                self::assertStringContainsString('private', strtolower($response->getHeaderLine('Cache-Control')));
                self::assertStringNotContainsString('public', strtolower($response->getHeaderLine('Cache-Control')));
                $vary = strtolower($response->getHeaderLine('Vary'));
                self::assertStringContainsString('authorization', $vary);
                self::assertStringContainsString('cookie', $vary);

                return true;
            });
        $handler = $this->handler(new Response(200, ['Cache-Control' => 'public, max-age=120'], 'private'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertStringContainsString('private', strtolower($response->getHeaderLine('Cache-Control')));
    }

    public function testPrivatePolicyWithoutCredentialsBypassesSharedStore(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true);
        $policies = $this->policyProvider($policy);
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->handler(new Response(200, [], 'origin'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/account'), $handler);

        self::assertSame('origin', (string) $response->getBody());
    }

    public function testMissingDateIsAddedBeforeCaching(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::once())
            ->method('store')
            ->willReturnCallback(static function (string $key, ResponseInterface $response): bool {
                self::assertTrue($response->hasHeader('Date'));
                self::assertNotNull(HttpDate::parse($response->getHeaderLine('Date')));

                return true;
            });
        $handler = $this->handler(new Response(200, [], 'hello'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/articles'), $handler);

        self::assertTrue($response->hasHeader('Date'));
        self::assertNotNull(HttpDate::parse($response->getHeaderLine('Date')));
    }

    public function testInvalidDateIsReplacedBeforeCaching(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::once())
            ->method('store')
            ->willReturnCallback(static function (string $key, ResponseInterface $response): bool {
                self::assertNotSame('tomorrow', $response->getHeaderLine('Date'));
                self::assertNotNull(HttpDate::parse($response->getHeaderLine('Date')));

                return true;
            });
        $handler = $this->handler(new Response(200, ['Date' => 'tomorrow'], 'hello'));

        $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/articles'), $handler);
    }

    public function testPolicyFreshnessDoesNotCacheResponseOlderThanPolicyTtl(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, ['Age' => '120'], 'hello'));

        $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/articles'), $handler);
    }

    public function testSetCookieResponseIsNeverStored(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, ['Set-Cookie' => 'session=abc'], 'hello'));

        $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/'), $handler);
    }

    public function testNonHeuristicStatusWithoutExplicitStoragePermissionIsNotCached(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, statuses: [500]);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(500, ['Cache-Control' => 'no-transform'], 'error'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/failure'), $handler);

        self::assertSame(500, $response->getStatusCode());
    }

    public function testPolicyCanMakeOtherwiseNonHeuristicStatusExplicitlyCacheable(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, statuses: [500]);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::once())
            ->method('store')
            ->willReturnCallback(static function (string $key, ResponseInterface $response, int $ttl): bool {
                self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
                self::assertSame(60, $ttl);

                return true;
            });
        $handler = $this->handler(new Response(500, [], 'error'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/failure'), $handler);

        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
    }

    public function testNoCacheResponseIsNotStoredOrRewritten(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, ['Cache-Control' => 'no-cache, must-revalidate'], 'hello'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/'), $handler);

        self::assertSame('no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
    }

    public function testIfNoneMatchUsesWeakComparisonAndBuildsRfc304Metadata(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createStub(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(new CachedResponse(
            status: 200,
            headers: [
                'ETag' => ['W/"abc"'],
                'Cache-Control' => ['public, max-age=60'],
                'Date' => ['Sun, 27 Sep 2026 20:00:00 GMT'],
                'Expires' => ['Sun, 27 Sep 2026 20:01:00 GMT'],
                'Content-Location' => ['/articles/1'],
            ],
            body: 'hello',
            storedAt: time(),
            freshUntil: time() + 60,
        ));
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest('GET', 'https://example.test/articles/1'))
            ->withHeader('If-None-Match', '"abc"');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('W/"abc"', $response->getHeaderLine('ETag'));
        self::assertSame('/articles/1', $response->getHeaderLine('Content-Location'));
        self::assertNotSame('', $response->getHeaderLine('Date'));
        self::assertNotSame('', $response->getHeaderLine('Expires'));
    }

    public function testConditionalRequestDoesNotTurnCachedErrorInto304(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, statuses: [404]);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createStub(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(new CachedResponse(
            status: 404,
            headers: ['Cache-Control' => ['public, max-age=60']],
            body: 'missing',
            storedAt: time(),
            freshUntil: time() + 60,
        ));
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest('GET', 'https://example.test/missing'))
            ->withHeader('If-None-Match', '*');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('missing', (string) $response->getBody());
    }

    public function testGeneratedEtagPreservesOriginalBodyPosition(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->method('store')->willReturn(false);
        $origin = new Response(200, [], 'abcdef');
        $origin->getBody()->seek(2);

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process(
            new ServerRequest('GET', 'https://example.test/resource'),
            $this->handler($origin),
        );

        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertSame(2, $response->getBody()->tell());
    }

    public function testGeneratedEtagIncludesRepresentationMetadata(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);

        [$policiesA, $keysA, $storeA, $invalidatorA] = $this->cacheMissDependencies($policy);
        $storeA->method('store')->willReturn(false);
        $html = $this->middleware($policiesA, $keysA, $storeA, $invalidatorA)->process(
            new ServerRequest('GET', 'https://example.test/resource'),
            $this->handler(new Response(200, ['Content-Type' => 'text/html'], 'same-body')),
        );

        [$policiesB, $keysB, $storeB, $invalidatorB] = $this->cacheMissDependencies($policy);
        $storeB->method('store')->willReturn(false);
        $json = $this->middleware($policiesB, $keysB, $storeB, $invalidatorB)->process(
            new ServerRequest('GET', 'https://example.test/resource'),
            $this->handler(new Response(200, ['Content-Type' => 'application/json'], 'same-body')),
        );

        self::assertNotSame('', $html->getHeaderLine('ETag'));
        self::assertNotSame('', $json->getHeaderLine('ETag'));
        self::assertNotSame($html->getHeaderLine('ETag'), $json->getHeaderLine('ETag'));
    }

    public function testHeadResponseDoesNotGetBodyDerivedEtag(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::once())
            ->method('store')
            ->willReturnCallback(static function (string $key, ResponseInterface $response): bool {
                self::assertFalse($response->hasHeader('ETag'));

                return true;
            });
        $handler = $this->handler(new Response(200));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('HEAD', 'https://example.test/articles/1'), $handler);

        self::assertFalse($response->hasHeader('ETag'));
    }

    public function testOnlyIfCachedMissReturnsGatewayTimeoutWithoutCallingOrigin(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest('GET', 'https://example.test/articles'))
            ->withHeader('Cache-Control', 'only-if-cached');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame(504, $response->getStatusCode());
    }

    public function testOnlyIfCachedWithoutPolicyDoesNotReachOrigin(): void
    {
        $policies = $this->createStub(CachePolicyProviderInterface::class);
        $policies->method('policyFor')->willReturn(null);
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest('GET', 'https://example.test/articles'))
            ->withHeader('Cache-Control', 'only-if-cached');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame(504, $response->getStatusCode());
    }

    public function testExpiredStoredEntryIsRejectedEvenIfBackendReturnsIt(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createStub(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(new CachedResponse(
            status: 200,
            headers: ['Cache-Control' => ['public, max-age=60']],
            body: 'stale',
            storedAt: time() - 120,
            freshUntil: time() - 1,
        ));
        $store->method('store')->willReturn(false);
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->handler(new Response(200, [], 'origin'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/articles'), $handler);

        self::assertSame('origin', (string) $response->getBody());
    }

    public function testMinFreshRejectsEntryWithoutEnoughRemainingFreshness(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createStub(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(new CachedResponse(
            status: 200,
            headers: ['Cache-Control' => ['public, max-age=60']],
            body: 'cached',
            storedAt: time(),
            freshUntil: time() + 5,
        ));
        $store->method('store')->willReturn(false);
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->handler(new Response(200, [], 'origin'));
        $request = (new ServerRequest('GET', 'https://example.test/articles'))
            ->withHeader('Cache-Control', 'min-fresh=10');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame('origin', (string) $response->getBody());
    }

    public function testOversizedResponseBypassesCacheBeforeEtagGeneration(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, [], '12345'));

        $response = $this->middleware($policies, $keys, $store, $invalidator, maxEntryBytes: 4)
            ->process(new ServerRequest('GET', 'https://example.test/large'), $handler);

        self::assertFalse($response->hasHeader('ETag'));
    }

    public function testIfMatchIsForwardedToOriginInsteadOfEvaluatedByCache(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->handler(new Response(200, [], 'origin'));
        $request = (new ServerRequest('GET', 'https://example.test/articles/1'))
            ->withHeader('If-Match', '"abc"');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame('origin', (string) $response->getBody());
    }

    public function testOriginOnlyPreconditionResponseIsNeverStoredUnderGenericKey(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, statuses: [412]);
        $policies = $this->policyProvider($policy);
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->handler(new Response(412));
        $request = (new ServerRequest('GET', 'https://example.test/articles/1'))
            ->withHeader('If-Match', '"stale"');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame(412, $response->getStatusCode());
    }

    public function testIfUnmodifiedSinceIsForwardedToOriginInsteadOfEvaluatedByCache(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $handler = $this->handler(new Response(200, [], 'origin'));
        $request = (new ServerRequest('GET', 'https://example.test/articles/1'))
            ->withHeader('If-Unmodified-Since', 'Sun, 27 Sep 2026 20:00:00 GMT');

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame('origin', (string) $response->getBody());
    }

    public function testCacheReadFailureIsLoggedAndFallsBackToOrigin(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createStub(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willThrowException(new RuntimeException('backend unavailable'));
        $store->method('store')->willReturn(false);
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'HTTP cache read failed.',
                self::callback(static fn(array $context): bool => $context['exception'] instanceof RuntimeException),
            );
        $handler = $this->handler(new Response(200, [], 'origin'));

        $response = $this->middleware($policies, $keys, $store, $invalidator, logger: $logger)
            ->process(new ServerRequest('GET', 'https://example.test/articles'), $handler);

        self::assertSame('origin', (string) $response->getBody());
    }

    /**
     * @return array{
     *     CachePolicyProviderInterface,
     *     CacheKeyGeneratorInterface,
     *     ResponseCacheStoreInterface&MockObject,
     *     CacheInvalidatorInterface
     * }
     */
    private function cacheMissDependencies(HttpCachePolicy $policy): array
    {
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::any())->method('fetch')->willReturn(null);
        $invalidator = $this->createStub(CacheInvalidatorInterface::class);

        return [$policies, $keys, $store, $invalidator];
    }

    private function policyProvider(HttpCachePolicy $policy): CachePolicyProviderInterface
    {
        $provider = $this->createStub(CachePolicyProviderInterface::class);
        $provider->method('policyFor')->willReturn($policy);

        return $provider;
    }

    private function keyGenerator(): CacheKeyGeneratorInterface
    {
        $keys = $this->createStub(CacheKeyGeneratorInterface::class);
        $keys->method('generate')->willReturn('cache-key');

        return $keys;
    }

    /**
     * @return RequestHandlerInterface&MockObject
     */
    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);

        return $handler;
    }

    private function middleware(
        CachePolicyProviderInterface $policies,
        CacheKeyGeneratorInterface $keys,
        ResponseCacheStoreInterface $store,
        CacheInvalidatorInterface $invalidator,
        int $maxEntryBytes = 8_388_608,
        ?LoggerInterface $logger = null,
    ): ResponseCacheMiddleware {
        $factory = new Psr17Factory();

        return new ResponseCacheMiddleware(
            policies: $policies,
            keys: $keys,
            store: $store,
            invalidator: $invalidator,
            responseFactory: $factory,
            streamFactory: $factory,
            maxEntryBytes: $maxEntryBytes,
            logger: $logger,
        );
    }
}
