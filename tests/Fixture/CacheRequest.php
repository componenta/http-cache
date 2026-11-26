<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests\Fixture;

use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Componenta\Http\Router\RouteRecord;
use Nyholm\Psr7\ServerRequest;

final class CacheRequest
{
    public static function forHandler(mixed $handler): ServerRequest
    {
        $route = RouteRecord::get(name: 'catalog', path: '/catalog', handler: $handler);
        $match = new MatchResult(
            name: $route->name,
            handler: $route->handler,
            middlewares: $route->middlewares,
            parameters: [],
            rr: $route,
        );

        return (new ServerRequest('GET', 'https://example.test/catalog'))
            ->withAttribute(MatchRouteMiddleware::ATTRIBUTE_MATCH_RESULT, $match);
    }
}
