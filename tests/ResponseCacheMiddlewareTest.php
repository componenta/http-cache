<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Key\RequestTarget;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

    public function testAuthenticatedCachedResponseIsForcedPrivateAndVariesByCookie(): void
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
                self::assertStringContainsString('cookie', strtolower($response->getHeaderLine('Vary')));

                return true;
            });
        $handler = $this->handler(new Response(200, ['Cache-Control' => 'public, max-age=120'], 'private'));

        $response = $this->middleware($policies, $keys, $store, $invalidator)->process($request, $handler);

        self::assertStringContainsString('private', strtolower($response->getHeaderLine('Cache-Control')));
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
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(new CachedResponse(
            status: 200,
            headers: [
                'ETag' => ['W/"abc"'],
                'Cache-Control' => ['public, max-age=60'],
                'Date' => ['Sun, 27 Sep 2026 20:00:00 GMT'],
                'Expires' => ['Sun, 27 Sep 2026 20:01:00 GMT'],
                'Content-Location' => ['/articles/1'],
                'Vary' => ['Accept-Encoding'],
            ],
            body: 'hello',
            storedAt: time(),
        ));
        $invalidator = $this->createMock(CacheInvalidatorInterface::class);
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

    /**
     * @return array{CachePolicyProviderInterface, CacheKeyGeneratorInterface, ResponseCacheStoreInterface, CacheInvalidatorInterface}
     */
    private function cacheMissDependencies(HttpCachePolicy $policy): array
    {
        $policies = $this->policyProvider($policy);
        $keys = $this->keyGenerator();
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->method('fetch')->willReturn(null);
        $invalidator = $this->createMock(CacheInvalidatorInterface::class);

        return [$policies, $keys, $store, $invalidator];
    }

    private function policyProvider(HttpCachePolicy $policy): CachePolicyProviderInterface
    {
        $provider = $this->createMock(CachePolicyProviderInterface::class);
        $provider->method('policyFor')->willReturn($policy);

        return $provider;
    }

    private function keyGenerator(): CacheKeyGeneratorInterface
    {
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->method('generate')->willReturn('cache-key');

        return $keys;
    }

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
    ): ResponseCacheMiddleware {
        $factory = new Psr17Factory();

        return new ResponseCacheMiddleware(
            policies: $policies,
            keys: $keys,
            store: $store,
            invalidator: $invalidator,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }
}
