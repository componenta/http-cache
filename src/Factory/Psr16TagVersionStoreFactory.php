<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Internal\ContainerService;
use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

final readonly class Psr16TagVersionStoreFactory
{
    public function __invoke(ContainerInterface $container): Psr16TagVersionStore
    {
        $config = ContainerService::get($container, Config::class);
        $prefix = $config->string(ConfigKey::KEY_PREFIX, 'http-cache');

        $ttl = $config->get(ConfigKey::TAG_GENERATION_TTL, Psr16TagVersionStore::DEFAULT_GENERATION_TTL);
        if (!is_int($ttl) || $ttl <= 0) {
            throw new RuntimeException(sprintf('%s config value must be a positive integer.', ConfigKey::TAG_GENERATION_TTL));
        }

        return new Psr16TagVersionStore(
            cache: ContainerService::get($container, CacheInterface::class),
            prefix: $prefix . ':tag',
            generationTtl: $ttl,
        );
    }
}
