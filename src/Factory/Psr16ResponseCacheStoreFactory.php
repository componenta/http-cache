<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Http\Cache\Store\Psr16ResponseCacheStore;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class Psr16ResponseCacheStoreFactory
{
    public function __invoke(ContainerInterface $container): Psr16ResponseCacheStore
    {
        return new Psr16ResponseCacheStore($container->get(CacheInterface::class));
    }
}
