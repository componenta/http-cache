<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Closure;
use Componenta\Http\Cache\Attribute\CacheResponse;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionMethod;

final readonly class AttributeCachePolicyProvider implements CachePolicyProviderInterface
{
    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy
    {
        $match = MatchRouteMiddleware::getMatchResultFromRequest($request);

        if ($match === null) {
            return null;
        }

        return $this->policyForHandler($match->handler->value);
    }

    private function policyForHandler(mixed $handler): ?HttpCachePolicy
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[1])) {
            $target = $handler[0];

            if (is_object($target) || (is_string($target) && class_exists($target))) {
                return $this->policyForClassAndMethod($target, $handler[1]);
            }

            return null;
        }

        if (is_string($handler)) {
            if (str_contains($handler, '::')) {
                [$class, $method] = explode('::', $handler, 2);

                return class_exists($class)
                    ? $this->policyForClassAndMethod($class, $method)
                    : null;
            }

            if (!class_exists($handler)) {
                return null;
            }

            return $this->policyForClassAndMethod($handler, $this->handlerMethod($handler));
        }

        if (!is_object($handler) || $handler instanceof Closure) {
            return null;
        }

        return $this->policyForClassAndMethod($handler, $this->handlerMethod($handler));
    }

    private function policyForClassAndMethod(object|string $target, ?string $method): ?HttpCachePolicy
    {
        $class = new ReflectionClass($target);

        if ($method !== null && $class->hasMethod($method)) {
            $policy = $this->policyFromAttributes($class->getMethod($method)->getAttributes(CacheResponse::class));

            if ($policy !== null) {
                return $policy;
            }
        }

        return $this->policyFromAttributes($class->getAttributes(CacheResponse::class));
    }

    private function handlerMethod(object|string $handler): ?string
    {
        if (is_a($handler, MiddlewareInterface::class, true)) {
            return 'process';
        }

        if (is_a($handler, RequestHandlerInterface::class, true)) {
            return 'handle';
        }

        return method_exists($handler, '__invoke') ? '__invoke' : null;
    }

    /**
     * @param list<\ReflectionAttribute<CacheResponse>> $attributes
     */
    private function policyFromAttributes(array $attributes): ?HttpCachePolicy
    {
        if ($attributes === []) {
            return null;
        }

        /** @var CacheResponse $attribute */
        $attribute = $attributes[0]->newInstance();

        return $attribute->toPolicy();
    }
}
