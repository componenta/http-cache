<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Key\DefaultCacheKeyGenerator;
use Psr\Container\ContainerInterface;

final readonly class DefaultCacheKeyGeneratorFactory
{
    public function __invoke(ContainerInterface $container): DefaultCacheKeyGenerator
    {
        $prefix = $container->get(Config::class)->string(ConfigKey::KEY_PREFIX, 'http-cache');

        return new DefaultCacheKeyGenerator(
            tags: $container->get(TagVersionStoreInterface::class),
            prefix: $prefix,
        );
    }
}
