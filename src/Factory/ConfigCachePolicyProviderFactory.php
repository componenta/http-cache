<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Policy\ConfigCachePolicyProvider;
use Psr\Container\ContainerInterface;
use RuntimeException;

final readonly class ConfigCachePolicyProviderFactory
{
    public function __invoke(ContainerInterface $container): ConfigCachePolicyProvider
    {
        $policies = $container->get(Config::class)->get(ConfigKey::POLICIES, []);

        if (!is_array($policies)) {
            throw new RuntimeException(sprintf('%s config value must be an array.', ConfigKey::POLICIES));
        }

        return new ConfigCachePolicyProvider($policies);
    }
}
