<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Benchmarks;

use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use Componenta\Http\Cache\Key\DefaultCacheKeyGenerator;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\AttributeCachePolicyProvider;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\ConfigCachePolicyProvider;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Store\Psr16ResponseCacheStore;
use Componenta\Http\Cache\Tests\Fixture\CacheRequest;
use Componenta\Http\Cache\Tests\Fixture\CallbackCachedHandler;
use Componenta\Http\Cache\Tests\Fixture\InMemoryCache;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Revs;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[BeforeMethods('setUp')]
final class ResponseCacheBench
{
    private ResponseCacheMiddleware $middleware;
    private ResponseCacheMiddleware $callbackMiddleware;
    private Psr16ResponseCacheStore $store;
    private Psr16TagVersionStore $tags;
    private ServerRequestInterface $hit;
    private ServerRequestInterface $callbackHit;
    private ServerRequestInterface $conditional;
    private ServerRequestInterface $miss;
    private ServerRequestInterface $bypass;
    private ServerRequestInterface $largeMiss;
    private RequestHandlerInterface $origin;
    private RequestHandlerInterface $largeOrigin;
    private string $missKey;
    private string $largeMissKey;

    /** @var list<string> */
    private array $manyTags;

    public function setUp(): void
    {
        $cache = new InMemoryCache();
        $this->store = new Psr16ResponseCacheStore($cache);
        $this->tags = new Psr16TagVersionStore($cache);
        $keys = new DefaultCacheKeyGenerator($this->tags);
        $policy = new HttpCachePolicy(ttl: 3600, tags: ['catalog']);
        $this->middleware = $this->middleware(new ConfigCachePolicyProvider(['catalog' => $policy]), $keys);
        $this->callbackMiddleware = $this->middleware(new AttributeCachePolicyProvider(), $keys);
        $this->origin = self::origin(str_repeat('x', 1024));
        $this->largeOrigin = self::origin(str_repeat('x', 1_048_576));
        $this->hit = CacheRequest::forHandler('catalog.handler');
        $this->callbackHit = CacheRequest::forHandler(CallbackCachedHandler::class)->withHeader('X-Category', '42');
        $first = $this->middleware->process($this->hit, $this->origin);
        $this->conditional = $this->hit->withHeader('If-None-Match', $first->getHeaderLine('ETag'));
        $this->callbackMiddleware->process($this->callbackHit, $this->origin);
        if (
            $this->middleware->process($this->hit, $this->origin)->getHeaderLine('X-Componenta-Cache') !== 'HIT'
            || $this->callbackMiddleware->process($this->callbackHit, $this->origin)->getHeaderLine('X-Componenta-Cache') !== 'HIT'
            || $this->middleware->process($this->conditional, $this->origin)->getStatusCode() !== 304
        ) {
            throw new RuntimeException('Benchmark fixture did not produce the expected cache hits.');
        }

        $this->miss = $this->hit->withUri($this->hit->getUri()->withQuery('miss=1'));
        $this->largeMiss = $this->hit->withUri($this->hit->getUri()->withQuery('large=1'));
        $this->missKey = $keys->generate($this->miss, $policy);
        $this->largeMissKey = $keys->generate($this->largeMiss, $policy);
        $this->bypass = $this->hit->withHeader('Cache-Control', 'no-store');
        $this->manyTags = [];
        for ($i = 0; $i < 64; ++$i) {
            $this->manyTags[] = 'benchmark:' . $i;
        }
        $this->tags->versions($this->manyTags);
    }

    public function benchHit(): void
    {
        $this->middleware->process($this->hit, $this->origin);
    }

    public function benchCallbackHit(): void
    {
        $this->callbackMiddleware->process($this->callbackHit, $this->origin);
    }

    public function benchNotModified(): void
    {
        $this->middleware->process($this->conditional, $this->origin);
    }

    public function benchMiss(): void
    {
        $this->store->delete($this->missKey);
        $this->middleware->process($this->miss, $this->origin);
    }

    public function benchBypass(): void
    {
        $this->middleware->process($this->bypass, $this->origin);
    }

    #[Revs(10)]
    public function benchLargeResponse(): void
    {
        $this->store->delete($this->largeMissKey);
        $this->middleware->process($this->largeMiss, $this->largeOrigin);
    }

    public function benchTagGenerations(): void
    {
        $this->tags->versions($this->manyTags);
    }

    public function benchInvalidation(): void
    {
        $this->tags->invalidateTags(['benchmark:0']);
    }

    private function middleware(CachePolicyProviderInterface $policies, DefaultCacheKeyGenerator $keys): ResponseCacheMiddleware
    {
        $factory = new Psr17Factory();

        return new ResponseCacheMiddleware(
            policies: $policies,
            keys: $keys,
            store: $this->store,
            invalidator: $this->tags,
            responseFactory: $factory,
            streamFactory: $factory,
            debugHeader: true,
        );
    }

    private static function origin(string $body): RequestHandlerInterface
    {
        return new class ($body) implements RequestHandlerInterface {
            public function __construct(private readonly string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/plain'], $this->body);
            }
        };
    }
}
