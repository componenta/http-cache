<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Http\Cache\Internal\ContainerService;
use Componenta\Http\Cache\Policy\AttributeCachePolicyProvider;
use Componenta\Http\Cache\Policy\CompositeCachePolicyProvider;
use Componenta\Http\Cache\Policy\ConfigCachePolicyProvider;
use Psr\Container\ContainerInterface;

final readonly class CompositeCachePolicyProviderFactory
{
    public function __invoke(ContainerInterface $container): CompositeCachePolicyProvider
    {
        return new CompositeCachePolicyProvider([
            ContainerService::get($container, ConfigCachePolicyProvider::class),
            ContainerService::get($container, AttributeCachePolicyProvider::class),
        ]);
    }
}
