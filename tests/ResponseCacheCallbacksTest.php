<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Attribute\CacheResponse;
use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Key\DefaultCacheKeyGenerator;
use Componenta\Http\Cache\Key\RequestTarget;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\AttributeCachePolicyProvider;
use Componenta\Http\Cache\Store\CacheFreshness;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Componenta\Http\Cache\Tests\Fixture\CacheRequest;
use Componenta\Http\Cache\Tests\Fixture\CallbackCachedHandler;
use Componenta\Http\Cache\Tests\Fixture\CallbackProbe;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class ResponseCacheCallbacksTest extends TestCase
{
    public function testHitResolvesCallbacksWithoutOriginAndDynamicTagsAreInvalidated(): void
    {
        $tags = new CallbackTagVersions();
        $store = new CallbackResponseStore();
        $origin = new CallbackOrigin();
        $middleware = $this->middleware(new DefaultCacheKeyGenerator($tags), $store, $tags);
        $probe = new CallbackProbe();
        $request = CacheRequest::forHandler(CallbackCachedHandler::class)
            ->withHeader('X-Category', '1')->withAttribute('probe', $probe);

        self::assertSame('origin:1', (string) $middleware->process($request, $origin)->getBody());
        $hit = $middleware->process($request, $origin);
        self::assertSame('origin:1', (string) $hit->getBody());
        self::assertSame('HIT', $hit->getHeaderLine('X-Componenta-Cache'));
        self::assertSame(1, $origin->calls);
        self::assertSame(['when', 'tags', 'when', 'tags'], $probe->calls);

        self::assertSame('origin:2', (string) $middleware->process($request->withHeader('X-Category', '2'), $origin)->getBody());
        self::assertSame('origin:1', (string) $middleware->process($request, $origin)->getBody());
        $tags->invalidateTags(['category:1']);
        self::assertSame('origin:3', (string) $middleware->process($request, $origin)->getBody());
        $tags->invalidateTags([RequestTarget::cacheTag($request)]);
        self::assertSame('origin:4', (string) $middleware->process($request, $origin)->getBody());
    }

    public function testVetoBypassesAnExistingEntryWithoutReadingOrWritingIt(): void
    {
        $tags = new CallbackTagVersions();
        $store = new CallbackResponseStore();
        $origin = new CallbackOrigin();
        $middleware = $this->middleware(new DefaultCacheKeyGenerator($tags), $store, $tags);
        $request = CacheRequest::forHandler(CallbackCachedHandler::class);
        $middleware->process($request, $origin);
        $probe = new CallbackProbe();
        $preview = $request->withAttribute('preview', true)->withAttribute('probe', $probe);

        self::assertSame('origin:2', (string) $middleware->process($preview, $origin)->getBody());
        $onlyCached = $middleware->process($preview->withHeader('Cache-Control', 'only-if-cached'), $origin);

        self::assertSame(504, $onlyCached->getStatusCode());
        self::assertSame(2, $origin->calls);
        self::assertSame(1, $store->reads);
        self::assertSame(1, $store->writes);
        self::assertSame(['when', 'when'], $probe->calls);
        self::assertSame('origin:1', (string) $middleware->process($request, $origin)->getBody());
    }

    public function testPolicyFailureDoesNotEnterBackendFallbackOrInvokeOrigin(): void
    {
        $failure = new RuntimeException('Request rule failed.');
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::never())->method('handle');
        $middleware = $this->middleware($keys, $store, new CallbackTagVersions());
        $request = CacheRequest::forHandler(CallbackCachedHandler::class)->withAttribute('failure', $failure);

        $this->expectExceptionObject($failure);

        $middleware->process($request, $origin);
    }

    #[DataProvider('uncacheableRequests')]
    public function testTruePredicatePreservesRequestSafetyGates(string $header, string $value): void
    {
        $keys = $this->createMock(CacheKeyGeneratorInterface::class);
        $keys->expects(self::never())->method('generate');
        $store = $this->createMock(ResponseCacheStoreInterface::class);
        $store->expects(self::never())->method('fetch');
        $store->expects(self::never())->method('store');
        $probe = new CallbackProbe();
        $request = CacheRequest::forHandler(CallbackCachedHandler::class)
            ->withAttribute('probe', $probe)->withHeader($header, $value);
        $origin = new CallbackOrigin();

        $response = $this->middleware($keys, $store, new CallbackTagVersions())->process($request, $origin);

        self::assertSame('origin:1', (string) $response->getBody());
        self::assertSame(['when', 'tags'], $probe->calls);
    }

    /** @return iterable<string, array{string, string}> */
    public static function uncacheableRequests(): iterable
    {
        yield 'authorization' => ['Authorization', 'Bearer credential'];
        yield 'cookie' => ['Cookie', 'session=credential'];
        yield 'no-store' => ['Cache-Control', 'no-store'];
        yield 'range' => ['Range', 'bytes=1-2'];
        yield 'if-match' => ['If-Match', '"version"'];
        yield 'if-unmodified-since' => ['If-Unmodified-Since', 'Sun, 27 Sep 2026 20:00:00 GMT'];
    }

    #[DataProvider('uncacheableResponses')]
    public function testTruePredicatePreservesResponseSafetyGates(string $header, string $value): void
    {
        $tags = new CallbackTagVersions();
        $store = new CallbackResponseStore();
        $origin = $this->createStub(RequestHandlerInterface::class);
        $origin->method('handle')->willReturn(new Response(200, [$header => $value], 'origin'));

        $response = $this->middleware(new DefaultCacheKeyGenerator($tags), $store, $tags)
            ->process(CacheRequest::forHandler(CallbackCachedHandler::class), $origin);

        self::assertSame('origin', (string) $response->getBody());
        self::assertSame(0, $store->writes);
    }

    /** @return iterable<string, array{string, string}> */
    public static function uncacheableResponses(): iterable
    {
        yield 'set-cookie' => ['Set-Cookie', 'session=credential'];
        yield 'undeclared vary' => ['Vary', 'Accept-Encoding'];
        yield 'wildcard vary' => ['Vary', '*'];
    }

    public function testUnsafeRequestDoesNotResolveCallbacksAndInvalidatesTarget(): void
    {
        $tags = new CallbackTagVersions();
        $store = new CallbackResponseStore();
        $origin = new CallbackOrigin();
        $request = CacheRequest::forHandler(CallbackCachedHandler::class)->withMethod('PUT')
            ->withAttribute('failure', new RuntimeException('Must not evaluate.'));

        $this->middleware(new DefaultCacheKeyGenerator($tags), $store, $tags)->process($request, $origin);

        self::assertSame(1, $origin->calls);
        self::assertSame(0, $store->reads);
        self::assertSame(0, $store->writes);
        self::assertSame([RequestTarget::cacheTag($request) => 1], $tags->versions([RequestTarget::cacheTag($request)]));
    }

    public function testResolvedCallbacksRetainAllExistingPrivateKeyPartitions(): void
    {
        $tags = new CallbackTagVersions();
        $keys = new DefaultCacheKeyGenerator($tags);
        $request = CacheRequest::forHandler(CallbackCachedHandler::class)
            ->withHeader('Authorization', 'Bearer first')->withHeader('Cookie', 'session=first');
        $static = new CacheResponse(ttl: 60, tags: ['catalog'], varyHeaders: ['Accept-Language'], private: true, allowAuthenticated: true);
        $dynamic = new CacheResponse(
            ttl: 60,
            tags: ['catalog'],
            varyHeaders: ['Accept-Language'],
            private: true,
            allowAuthenticated: true,
            when: static fn(ServerRequestInterface $request): bool => true,
            tagsFactory: static fn(ServerRequestInterface $request): array => [],
        );
        $policy = $dynamic->toPolicy($request);
        $key = $keys->generate($request, $policy);

        self::assertSame($keys->generate($request, $static->toPolicy()), $key);
        foreach ([
            $request->withHeader('Authorization', 'Bearer second'),
            $request->withHeader('Cookie', 'session=second'),
            $request->withHeader('Accept-Language', 'ru'),
            $request->withUri($request->getUri()->withHost('other.example.test')),
            $request->withUri($request->getUri()->withQuery('page=2')),
            $request->withMethod('HEAD'),
        ] as $other) {
            self::assertNotSame($key, $keys->generate($other, $dynamic->toPolicy($other)));
        }
        self::assertNotSame($key, (new DefaultCacheKeyGenerator($tags, prefix: 'other'))->generate($request, $policy));
    }

    private function middleware(
        CacheKeyGeneratorInterface $keys,
        ResponseCacheStoreInterface $store,
        TagVersionStoreInterface $tags,
    ): ResponseCacheMiddleware {
        $factory = new Psr17Factory();

        return new ResponseCacheMiddleware(
            policies: new AttributeCachePolicyProvider(),
            keys: $keys,
            store: $store,
            invalidator: $tags,
            responseFactory: $factory,
            streamFactory: $factory,
            debugHeader: true,
        );
    }
}

final class CallbackOrigin implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'origin:' . ++$this->calls);
    }
}

final class CallbackTagVersions implements TagVersionStoreInterface
{
    /** @var array<string, int> */
    private array $generations = [];

    public function versions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = $this->generations[$tag] ?? 0;
        }
        ksort($versions);

        return $versions;
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->generations[$tag] = ($this->generations[$tag] ?? 0) + 1;
        }
    }
}

final class CallbackResponseStore implements ResponseCacheStoreInterface
{
    /** @var array<string, CachedResponse> */
    private array $entries = [];
    public int $reads = 0;
    public int $writes = 0;

    public function fetch(string $key): ?CachedResponse
    {
        ++$this->reads;

        return $this->entries[$key] ?? null;
    }

    public function store(string $key, ResponseInterface $response, CacheFreshness $freshness): bool
    {
        ++$this->writes;
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }
        $storedAt = time();
        if ($freshness->remainingAt($storedAt) <= 0) {
            return false;
        }
        $this->entries[$key] = new CachedResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: (string) $response->getBody(),
            storedAt: $storedAt,
            ageAtStore: $freshness->ageAt($storedAt),
            freshUntil: $freshness->freshUntil,
        );

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}
