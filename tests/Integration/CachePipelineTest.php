<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests\Integration;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey as DependencyKey;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\ConfigProvider;
use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Tests\Fixture\CacheRequest;
use Componenta\Http\Cache\Tests\Fixture\InMemoryCache;
use Componenta\Http\Cache\Tests\Fixture\MutableClock;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\PipelineFactory;
use Componenta\Http\Router\Middleware\DispatchRouteMiddleware;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\Resolver\RouteHandlerResolver;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Router;
use Componenta\Http\Router\Routes;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

final class CachePipelineTest extends TestCase
{
    public function testCacheHitNeverBypassesOuterAuthorizationAfterRevocation(): void
    {
        $container = $this->container(private: true);
        $cache = $container->get(ResponseCacheMiddleware::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        $guard = new class implements MiddlewareInterface {
            public bool $authorized = true;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->authorized ? $handler->handle($request) : new Response(403);
            }
        };
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::once())->method('handle')->willReturn(new Response(200, [], 'private'));
        $pipeline = (new PipelineFactory())->createMiddlewarePipeline([$guard, $cache], $origin);
        $request = CacheRequest::forHandler('catalog.handler')->withHeader('Authorization', 'Bearer same-token');

        self::assertSame('private', (string) $pipeline->handle($request)->getBody());
        self::assertSame('HIT', $pipeline->handle($request)->getHeaderLine('X-Componenta-Cache'));
        $guard->authorized = false;
        self::assertSame(403, $pipeline->handle($request)->getStatusCode());
    }

    public function testInvalidationDuringOriginCannotPublishStaleBodyUnderNewGeneration(): void
    {
        $container = $this->container(private: false);
        $cache = $container->get(ResponseCacheMiddleware::class);
        $invalidator = $container->get(CacheInvalidatorInterface::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        self::assertInstanceOf(CacheInvalidatorInterface::class, $invalidator);
        $request = CacheRequest::forHandler('catalog.handler');
        $calls = 0;
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::exactly(2))->method('handle')->willReturnCallback(
            static function () use ($invalidator, &$calls): ResponseInterface {
                if (++$calls === 1) {
                    $invalidator->invalidateTags(['catalog']);
                    return new Response(200, [], 'stale');
                }
                return new Response(200, [], 'fresh');
            },
        );

        self::assertSame('stale', (string) $cache->process($request, $origin)->getBody());
        self::assertSame('fresh', (string) $cache->process($request, $origin)->getBody());
        $hit = $cache->process($request, $origin);
        self::assertSame('HIT', $hit->getHeaderLine('X-Componenta-Cache'));
        self::assertSame('fresh', (string) $hit->getBody());
    }

    public function testNonStorableRefreshCannotReviveSupersededResponse(): void
    {
        $cache = $this->container(private: false)->get(ResponseCacheMiddleware::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        $request = CacheRequest::forHandler('catalog.handler');
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::exactly(3))->method('handle')->willReturnOnConsecutiveCalls(
            new Response(200, [], 'old'),
            new Response(200, ['Cache-Control' => 'no-store'], 'changed'),
            new Response(200, [], 'new'),
        );

        self::assertSame('old', (string) $cache->process($request, $origin)->getBody());
        $refresh = $cache->process($request->withHeader('Cache-Control', 'no-cache'), $origin);
        self::assertSame('changed', (string) $refresh->getBody());
        self::assertSame('BYPASS', $refresh->getHeaderLine('X-Componenta-Cache'));
        self::assertSame('new', (string) $cache->process($request, $origin)->getBody());
        self::assertSame('HIT', $cache->process($request, $origin)->getHeaderLine('X-Componenta-Cache'));
    }

    public function testRequestNoStoreDoesNotEvictAnExistingEntry(): void
    {
        $cache = $this->container(private: false)->get(ResponseCacheMiddleware::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        $request = CacheRequest::forHandler('catalog.handler');
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::exactly(2))->method('handle')->willReturnOnConsecutiveCalls(
            new Response(200, [], 'cached'),
            new Response(200, [], 'uncached'),
        );

        $cache->process($request, $origin);
        self::assertSame('uncached', (string) $cache->process($request->withHeader('Cache-Control', 'no-store'), $origin)->getBody());
        self::assertSame('cached', (string) $cache->process($request, $origin)->getBody());
    }

    public function testPublicCacheBypassesParsedCookiesWithoutWireHeader(): void
    {
        $cache = $this->container(private: false)->get(ResponseCacheMiddleware::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        $request = CacheRequest::forHandler('catalog.handler');
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::exactly(2))->method('handle')->willReturnOnConsecutiveCalls(
            new Response(200, [], 'anonymous'),
            new Response(200, [], 'personal'),
        );

        self::assertSame('anonymous', (string) $cache->process($request, $origin)->getBody());
        self::assertSame('personal', (string) $cache->process($request->withCookieParams(['session' => 'token']), $origin)->getBody());
        self::assertSame('anonymous', (string) $cache->process($request, $origin)->getBody());
    }

    public function testGetBodyDoesNotReuseOrPopulateBodyIndependentCache(): void
    {
        $cache = $this->container(private: false)->get(ResponseCacheMiddleware::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        $request = CacheRequest::forHandler('catalog.handler');
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::exactly(2))->method('handle')->willReturnOnConsecutiveCalls(
            new Response(200, [], 'body independent'),
            new Response(200, [], 'body dependent'),
        );
        $withBody = $request->withBody((new Psr17Factory())->createStream('input'));

        self::assertSame('body independent', (string) $cache->process($request, $origin)->getBody());
        self::assertSame('body dependent', (string) $cache->process($withBody, $origin)->getBody());
        self::assertSame('body independent', (string) $cache->process($request, $origin)->getBody());
    }

    public function testActualRouterDispatchAndUnsafeInvalidationAcrossPipeline(): void
    {
        $container = $this->container(private: false);
        $cache = $container->get(ResponseCacheMiddleware::class);
        $executor = $container->get(CallableExecutorInterface::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        self::assertInstanceOf(CallableExecutorInterface::class, $executor);
        $reads = 0;
        $revisions = [1 => 1, 2 => 1];
        $routes = new Routes();
        $routes->addRoute(RouteRecord::get(
            'catalog',
            '/catalog/[id]',
            static function (ServerRequestInterface $request) use (&$reads, &$revisions): ResponseInterface {
                ++$reads;
                $id = $request->getAttribute('id');
                self::assertIsInt($id);
                return new Response(200, [], sprintf('%d:%d', $id, $revisions[$id]));
            },
        ));
        $routes->addRoute(RouteRecord::put(
            'catalog.update',
            '/catalog/[id]',
            static function (ServerRequestInterface $request) use (&$revisions): ResponseInterface {
                $id = $request->getAttribute('id');
                self::assertIsInt($id);
                ++$revisions[$id];
                return new Response(204);
            },
        ));
        $terminal = $this->createMock(RequestHandlerInterface::class);
        $terminal->expects(self::never())->method('handle');
        $pipeline = (new PipelineFactory())->createMiddlewarePipeline([
            new MatchRouteMiddleware(Router::fromDnf($routes)),
            $cache,
            new DispatchRouteMiddleware(new MiddlewareFactory(new RouteHandlerResolver($executor))),
        ], $terminal);
        $one = new ServerRequest('GET', 'https://example.test/catalog/1');
        $two = new ServerRequest('GET', 'https://example.test/catalog/2');

        self::assertSame('1:1', (string) $pipeline->handle($one)->getBody());
        self::assertSame('MISS', $pipeline->handle($two)->getHeaderLine('X-Componenta-Cache'));
        self::assertSame('HIT', $pipeline->handle($one)->getHeaderLine('X-Componenta-Cache'));
        self::assertSame('2:1', (string) $pipeline->handle($two)->getBody());
        self::assertSame(204, $pipeline->handle($one->withMethod('PUT'))->getStatusCode());
        self::assertSame('1:2', (string) $pipeline->handle($one)->getBody());
        self::assertSame('2:1', (string) $pipeline->handle($two)->getBody());
        self::assertSame(3, $reads);
    }

    public function testCustomGenerationStoreAlsoReceivesDefaultInvalidation(): void
    {
        $generations = new Psr16TagVersionStore(new InMemoryCache());
        $container = $this->container(private: false, generations: $generations);
        $cache = $container->get(ResponseCacheMiddleware::class);
        self::assertInstanceOf(ResponseCacheMiddleware::class, $cache);
        self::assertSame($generations, $container->get(TagVersionStoreInterface::class));
        self::assertSame($generations, $container->get(CacheInvalidatorInterface::class));

        $request = CacheRequest::forHandler('catalog.handler');
        $origin = $this->createMock(RequestHandlerInterface::class);
        $origin->expects(self::exactly(3))->method('handle')->willReturnOnConsecutiveCalls(
            new Response(200, [], 'old'),
            new Response(204),
            new Response(200, [], 'new'),
        );
        self::assertSame('old', (string) $cache->process($request, $origin)->getBody());
        self::assertSame('HIT', $cache->process($request, $origin)->getHeaderLine('X-Componenta-Cache'));
        self::assertSame(204, $cache->process($request->withMethod('PUT'), $origin)->getStatusCode());
        self::assertSame('new', (string) $cache->process($request, $origin)->getBody());
    }

    private function container(bool $private, ?TagVersionStoreInterface $generations = null): \Psr\Container\ContainerInterface
    {
        $psr17 = new Psr17Factory();
        $clock = new MutableClock(new DateTimeImmutable('@1000'));
        $composition = (new ConfigFactory())->create(
            new Environment([]),
            new ConfigProvider(),
            static fn (): array => [
                ConfigKey::POLICIES => [
                    'catalog' => [
                        'ttl' => 60,
                        'private' => $private,
                        'allow_authenticated' => $private,
                        'tags' => ['catalog'],
                    ],
                ],
                ConfigKey::DEBUG_HEADER => true,
                DependencyKey::DEPENDENCIES => [
                    DependencyKey::ALIASES => $generations === null ? [] : [TagVersionStoreInterface::class => 'test.generations'],
                    DependencyKey::SERVICES => [
                        ...($generations === null ? [] : ['test.generations' => $generations]),
                        CacheInterface::class => new InMemoryCache(),
                        ClockInterface::class => $clock,
                        ResponseFactoryInterface::class => $psr17,
                        StreamFactoryInterface::class => $psr17,
                    ],
                ],
            ],
        );
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    }
}
