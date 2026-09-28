<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Psr\Http\Message\ServerRequestInterface;

final readonly class CompositeCachePolicyProvider implements CachePolicyProviderInterface
{
    /**
     * @param list<CachePolicyProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
    ) {}

    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy
    {
        foreach ($this->providers as $provider) {
            $policy = $provider->policyFor($request);

            if ($policy !== null) {
                return $policy;
            }
        }

        return null;
    }
}
