<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Closure;
use Componenta\Http\Cache\Attribute\CacheResponse;
use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionAttribute;
use ReflectionClass;

final class AttributeCachePolicyProvider implements CachePolicyProviderInterface
{
    private const int MAX_METADATA_ENTRIES = 256;

    /** @var array<string, ReflectionAttribute<CacheResponse>|null> */
    private array $declarations = [];

    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy
    {
        $match = MatchRouteMiddleware::getMatchResultFromRequest($request);

        if ($match === null) {
            return null;
        }

        return $this->policyForHandler($match->handler->value, $request);
    }

    private function policyForHandler(mixed $handler, ServerRequestInterface $request): ?HttpCachePolicy
    {
        if (is_array($handler) && array_is_list($handler) && count($handler) === 2 && is_string($handler[1])) {
            $target = $handler[0];

            if (is_object($target) || (is_string($target) && class_exists($target))) {
                return $this->policyForClassAndMethod($target, $handler[1], $request);
            }

            return null;
        }

        if (is_string($handler)) {
            if (str_contains($handler, '::')) {
                [$class, $method] = explode('::', $handler, 2);

                return class_exists($class)
                    ? $this->policyForClassAndMethod($class, $method, $request)
                    : null;
            }

            if (!class_exists($handler)) {
                return null;
            }

            return $this->policyForClassAndMethod($handler, $this->handlerMethod($handler), $request);
        }

        if (!is_object($handler) || $handler instanceof Closure) {
            return null;
        }

        return $this->policyForClassAndMethod($handler, $this->handlerMethod($handler), $request);
    }

    private function policyForClassAndMethod(object|string $target, ?string $method, ServerRequestInterface $request): ?HttpCachePolicy
    {
        if (is_string($target) && !class_exists($target)) {
            return null;
        }

        $className = is_object($target) ? $target::class : $target;
        $key = strtolower($className . "\0" . ($method ?? ''));

        if (!array_key_exists($key, $this->declarations)) {
            $class = new ReflectionClass($className);
            $declaration = null;
            if ($method !== null && $class->hasMethod($method)) {
                $declaration = $class->getMethod($method)->getAttributes(CacheResponse::class)[0] ?? null;
            }
            $declaration ??= $class->getAttributes(CacheResponse::class)[0] ?? null;

            if (count($this->declarations) >= self::MAX_METADATA_ENTRIES) {
                array_shift($this->declarations);
            }
            $this->declarations[$key] = $declaration;
        }

        // Cache reflection metadata only. Never keep the attribute instance,
        // request, callback result, or resolved policy in a shared provider.
        return $this->declarations[$key]?->newInstance()->toPolicy($request);
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
}
