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
use Componenta\Http\Cache\Tests\Fixture\MutableClock;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
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

    public function testOnlyIfCachedUnsafeRequestDoesNotExecuteOrigin(): void
    {
        $policies = $this->createMock(CachePolicyProviderInterface::class);
        $policies->expects(self::never())->method('policyFor');
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $invalidator = $this->createMock(CacheInvalidatorInterface::class);
        $invalidator->expects(self::never())->method('invalidateTags');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest('PUT', 'https://example.test/items/42'))
            ->withHeader('Cache-Control', 'only-if-cached');

        self::assertSame(504, $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler)->getStatusCode());
    }

    public function testNonstandardLowercaseMethodIsNeverServedFromCache(): void
    {
        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('get');
        $request->method('getHeader')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getUri')->willReturn(new \Nyholm\Psr7\Uri('https://example.test/items'));
        $request->method('getRequestTarget')->willReturn('/items');
        $policies = $this->createMock(CachePolicyProviderInterface::class);
        $policies->expects(self::never())->method('policyFor');
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $invalidator = $this->createMock(CacheInvalidatorInterface::class);
        $invalidator->expects(self::once())->method('invalidateTags');
        $handler = $this->handler(new Response(200, [], 'origin'));

        self::assertSame('origin', (string) $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler)->getBody());
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
                self::assertGreaterThan(0, $ttl);
                self::assertLessThanOrEqual(60, $ttl);
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

    public function testPrivatePolicyProtectsEvenNonStorableResponses(): void
    {
        $request = (new ServerRequest('GET', 'https://example.test/account'))
            ->withHeader('Authorization', 'Bearer secret');
        $policy = new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true);
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies($policy);
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, [
            'Cache-Control' => 'public, s-maxage=600, no-store',
            'Set-Cookie' => 'session=rotated',
        ], 'private'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('authorization', strtolower($response->getHeaderLine('Vary')));
        self::assertStringContainsString('cookie', strtolower($response->getHeaderLine('Vary')));
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
                self::assertGreaterThan(0, $ttl);
                self::assertLessThanOrEqual(60, $ttl);

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

    public function testConditionalHitDoesNotReconstructTheStoredBody(): void
    {
        $factory = new Psr17Factory();
        $streams = $this->createMock(\Psr\Http\Message\StreamFactoryInterface::class);
        $streams->expects(self::never())->method('createStream');
        $store = $this->createStub(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(new CachedResponse(
            status: 200,
            headers: ['eTaG' => ['W/"abc"']],
            body: 'large representation',
            storedAt: time(),
            freshUntil: time() + 60,
        ));
        $middleware = new ResponseCacheMiddleware(
            policies: $this->policyProvider(new HttpCachePolicy(ttl: 60)),
            keys: $this->keyGenerator(),
            store: $store,
            invalidator: $this->createStub(CacheInvalidatorInterface::class),
            responseFactory: $factory,
            streamFactory: $streams,
        );
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::never())->method('handle');
        $request = (new ServerRequest('GET', 'https://example.test/items'))
            ->withHeader('If-None-Match', '"abc"');

        $response = $middleware->process($request, $origin);
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('W/"abc"', $response->getHeaderLine('ETag'));
        self::assertSame('', (string) $response->getBody());
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
        $store->expects(self::once())->method('store')->willReturn(false);
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
        $storeA->expects(self::once())->method('store')->willReturn(false);
        $html = $this->middleware($policiesA, $keysA, $storeA, $invalidatorA)->process(
            new ServerRequest('GET', 'https://example.test/resource'),
            $this->handler(new Response(200, ['Content-Type' => 'text/html'], 'same-body')),
        );

        [$policiesB, $keysB, $storeB, $invalidatorB] = $this->cacheMissDependencies($policy);
        $storeB->expects(self::once())->method('store')->willReturn(false);
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
        $store->expects(self::never())->method('store');
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

    public function testEtagPreparationConsumesFreshnessBudget(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('@100'));
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies(new HttpCachePolicy(ttl: 1));
        $store->expects(self::never())->method('store');
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn(4);
        $body->method('tell')->willReturn(0);
        $body->method('eof')->willReturn(true);
        $body->method('read')->willReturnCallback(static function () use ($clock): string {
            $clock->advance(1);
            return 'body';
        });
        $response = (new Response(200))->withBody($body);

        $result = $this->middleware($policies, $keys, $store, $invalidator, clock: $clock)
            ->process(new ServerRequest('GET', 'https://example.test/stream'), $this->handler($response));

        self::assertSame(200, $result->getStatusCode());
        self::assertSame($body, $result->getBody());
    }

    public function testEtagReadFailureReturnsOriginResponseWithoutCacheWrite(): void
    {
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies(new HttpCachePolicy(ttl: 60));
        $store->expects(self::never())->method('store');
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn(4);
        $body->method('tell')->willReturn(2);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willThrowException(new RuntimeException('read failed'));
        $body->expects(self::once())->method('seek')->with(2);
        $response = (new Response(200))->withBody($body);

        $result = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/stream'), $this->handler($response));

        self::assertSame(200, $result->getStatusCode());
        self::assertSame($body, $result->getBody());
        self::assertFalse($result->hasHeader('ETag'));
    }

    public function testIncompleteContentLengthIsNotStoredEvenWithOriginEtag(): void
    {
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies(new HttpCachePolicy(ttl: 60));
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, ['Content-Length' => '5', 'ETag' => '"origin"'], 'bad'));

        $result = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/items'), $handler);

        self::assertSame('bad', (string) $result->getBody());
    }

    public function testOffloadedResponseIsNotStored(): void
    {
        [$policies, $keys, $store, $invalidator] = $this->cacheMissDependencies(new HttpCachePolicy(ttl: 60));
        $store->expects(self::never())->method('store');
        $handler = $this->handler(new Response(200, ['X-Accel-Redirect' => '/internal/file']));

        $result = $this->middleware($policies, $keys, $store, $invalidator)
            ->process(new ServerRequest('GET', 'https://example.test/items'), $handler);

        self::assertSame('/internal/file', $result->getHeaderLine('X-Accel-Redirect'));
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
        $store->method('fetch')->willReturn(null);
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
        ?ClockInterface $clock = null,
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
            clock: $clock ?? new \Componenta\Http\Cache\Internal\SystemClock(),
        );
    }
}
