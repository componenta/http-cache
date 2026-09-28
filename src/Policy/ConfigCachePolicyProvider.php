<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

final class ConfigCachePolicyProvider implements CachePolicyProviderInterface
{
    /** @var array<string, HttpCachePolicy|array<string, mixed>> */
    private readonly array $policies;

    /** @var array<string, HttpCachePolicy> */
    private array $resolved = [];

    /**
     * @param array<array-key, mixed> $policies
     */
    public function __construct(array $policies)
    {
        $normalized = [];

        foreach ($policies as $routeName => $policy) {
            if (!is_string($routeName) || $routeName === '') {
                throw new InvalidArgumentException('HTTP cache policy route names must be non-empty strings.');
            }

            if ($policy instanceof HttpCachePolicy) {
                $normalized[$routeName] = $policy;
                continue;
            }

            if (!is_array($policy) || !self::hasStringKeys($policy)) {
                throw new InvalidArgumentException(sprintf(
                    'HTTP cache policy for route "%s" must be an array or %s.',
                    $routeName,
                    HttpCachePolicy::class,
                ));
            }

            $normalized[$routeName] = $policy;
        }

        $this->policies = $normalized;
    }

    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy
    {
        $match = MatchRouteMiddleware::getMatchResultFromRequest($request);

        if ($match === null || !array_key_exists($match->name, $this->policies)) {
            return null;
        }

        return $this->resolved[$match->name] ??= $this->resolvePolicy($this->policies[$match->name]);
    }

    /**
     * @param HttpCachePolicy|array<string, mixed> $policy
     */
    private function resolvePolicy(HttpCachePolicy|array $policy): HttpCachePolicy
    {
        return $policy instanceof HttpCachePolicy
            ? $policy
            : HttpCachePolicy::fromArray($policy);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function hasStringKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
