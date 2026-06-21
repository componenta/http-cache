<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Componenta\Http\Router\Middleware\MatchRouteMiddleware;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

final class ConfigCachePolicyProvider implements CachePolicyProviderInterface
{
    /** @var array<string, HttpCachePolicy> */
    private array $resolved = [];

    /**
     * @param array<string, HttpCachePolicy|array<string, mixed>> $policies
     */
    public function __construct(
        private readonly array $policies,
    ) {}

    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy
    {
        $match = MatchRouteMiddleware::getMatchResultFromRequest($request);

        if ($match === null || !array_key_exists($match->name, $this->policies)) {
            return null;
        }

        return $this->resolved[$match->name] ??= $this->resolvePolicy($match->name, $this->policies[$match->name]);
    }

    private function resolvePolicy(string $routeName, mixed $policy): HttpCachePolicy
    {
        if ($policy instanceof HttpCachePolicy) {
            return $policy;
        }

        if (is_array($policy)) {
            return HttpCachePolicy::fromArray($policy);
        }

        throw new InvalidArgumentException(sprintf(
            'HTTP cache policy for route "%s" must be an array or %s.',
            $routeName,
            HttpCachePolicy::class,
        ));
    }
}
