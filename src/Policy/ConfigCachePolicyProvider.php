<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConfigCachePolicyProvider implements CachePolicyProviderInterface
{
    /** @var array<array-key, HttpCachePolicy> */
    private array $policies;

    /**
     * @param array<array-key, mixed> $policies
     */
    public function __construct(array $policies)
    {
        $normalized = [];

        foreach ($policies as $routeName => $policy) {
            // PHP converts integer-shaped string array keys into integers.
            // Router names are strings, but a configured map must accept both
            // representations of the same numeric route name.
            $routeName = (string) $routeName;
            if ($routeName === '') {
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

            /** @var array<string, mixed> $policy */
            $normalized[$routeName] = HttpCachePolicy::fromArray($policy);
        }

        $this->policies = $normalized;
    }

    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy
    {
        $match = MatchRouteMiddleware::getMatchResultFromRequest($request);

        return $match === null ? null : ($this->policies[$match->name] ?? null);
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
