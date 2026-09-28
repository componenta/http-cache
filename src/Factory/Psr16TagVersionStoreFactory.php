<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Internal\ContainerService;
use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class Psr16TagVersionStoreFactory
{
    public function __invoke(ContainerInterface $container): Psr16TagVersionStore
    {
        $config = ContainerService::get($container, Config::class);
        $prefix = $config->string(ConfigKey::KEY_PREFIX, 'http-cache');

        return new Psr16TagVersionStore(
            cache: ContainerService::get($container, CacheInterface::class),
            prefix: $prefix . ':tag',
        );
    }
}
