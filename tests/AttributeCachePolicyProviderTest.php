<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Attribute\CacheResponse;
use Componenta\Http\Cache\Policy\AttributeCachePolicyProvider;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\RouteRecord;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class AttributeCachePolicyProviderTest extends TestCase
{
    public function testResolvesClassLevelAttribute(): void
    {
        $request = $this->requestFor(AttributeCachedHandler::class, 'class.cached');

        $policy = (new AttributeCachePolicyProvider())->policyFor($request);

        self::assertNotNull($policy);
        self::assertSame(90, $policy->ttl);
        self::assertSame(['accept-language'], $policy->varyHeaders);
    }

    public function testMethodAttributeOverridesClassAttribute(): void
    {
        $request = $this->requestFor([MethodCachedHandler::class, 'show'], 'method.cached');

        $policy = (new AttributeCachePolicyProvider())->policyFor($request);

        self::assertNotNull($policy);
        self::assertSame(30, $policy->ttl);
    }

    public function testProviderCanReuseMetadataWithoutReusingRequestDerivedPolicy(): void
    {
        $provider = new AttributeCachePolicyProvider();
        $handler = \Componenta\Http\Cache\Tests\Fixture\CallbackCachedHandler::class;
        $first = $this->requestFor($handler, 'first')->withHeader('X-Category', '10');
        $second = $this->requestFor($handler, 'second')->withHeader('X-Category', '20');
        $veto = $second->withAttribute('preview', true);

        $firstPolicy = $provider->policyFor($first);
        self::assertNotNull($firstPolicy);
        self::assertSame(['catalog', 'category:10'], $firstPolicy->tags);

        $secondPolicy = $provider->policyFor($second);
        self::assertNotNull($secondPolicy);
        self::assertSame(['catalog', 'category:20'], $secondPolicy->tags);

        $vetoPolicy = $provider->policyFor($veto);
        self::assertNotNull($vetoPolicy);
        self::assertFalse($vetoPolicy->enabled);

        $repeatedPolicy = $provider->policyFor($first);
        self::assertSame(['catalog', 'category:10'], $repeatedPolicy->tags);
    }

    public function testReturnsNullForOpaqueServiceIdentifier(): void
    {
        $request = $this->requestFor('article.handler', 'service.cached');

        self::assertNull((new AttributeCachePolicyProvider())->policyFor($request));
    }

    private function requestFor(mixed $handler, string $name): ServerRequest
    {
        $route = RouteRecord::get(
            name: $name,
            path: '/articles',
            handler: $handler,
        );
        $match = new MatchResult(
            name: $route->name,
            handler: $route->handler,
            middlewares: $route->middlewares,
            parameters: [],
            rr: $route,
        );

        return (new ServerRequest('GET', 'https://example.test/articles'))
            ->withAttribute(MatchRouteMiddleware::ATTRIBUTE_MATCH_RESULT, $match);
    }
}

#[CacheResponse(ttl: 90, varyHeaders: ['Accept-Language'])]
final class AttributeCachedHandler
{
    public function __invoke(): void {}
}

#[CacheResponse(ttl: 120)]
final class MethodCachedHandler
{
    #[CacheResponse(ttl: 30)]
    public static function show(): void {}
}
