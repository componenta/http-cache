<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Policy\ConfigCachePolicyProvider;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\RouteRecord;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ConfigCachePolicyProviderTest extends TestCase
{
    public function testResolvesPolicyFromRouterTwoMatchResult(): void
    {
        $route = RouteRecord::get(
            name: 'articles.index',
            path: '/articles',
            handler: static fn (): null => null,
        );
        $match = new MatchResult(
            name: $route->name,
            handler: $route->handler,
            middlewares: $route->middlewares,
            parameters: [],
            rr: $route,
        );
        $request = (new ServerRequest('GET', '/articles'))
            ->withAttribute(MatchRouteMiddleware::ATTRIBUTE_MATCH_RESULT, $match);
        $provider = new ConfigCachePolicyProvider([
            'articles.index' => ['ttl' => 60],
        ]);

        $policy = $provider->policyFor($request);

        self::assertInstanceOf(HttpCachePolicy::class, $policy);
        self::assertSame(60, $policy->ttl);
        self::assertSame('public, max-age=60', $policy->cacheControlValue());
    }

    public function testReturnsNullWithoutRouteMatch(): void
    {
        $provider = new ConfigCachePolicyProvider([
            'articles.index' => ['ttl' => 60],
        ]);

        self::assertNull($provider->policyFor(new ServerRequest('GET', '/articles')));
    }
}
