<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Internal\ContainerService;
use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Key\DefaultCacheKeyGenerator;
use Psr\Container\ContainerInterface;

final readonly class DefaultCacheKeyGeneratorFactory
{
    public function __invoke(ContainerInterface $container): DefaultCacheKeyGenerator
    {
        $config = ContainerService::get($container, Config::class);

        return new DefaultCacheKeyGenerator(
            tags: ContainerService::get($container, TagVersionStoreInterface::class),
            prefix: $config->string(ConfigKey::KEY_PREFIX, 'http-cache'),
        );
    }
}
