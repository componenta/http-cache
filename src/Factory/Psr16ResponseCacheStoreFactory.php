<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Internal\ContainerService;
use Componenta\Http\Cache\Store\Psr16ResponseCacheStore;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

final readonly class Psr16ResponseCacheStoreFactory
{
    private const int DEFAULT_MAX_ENTRY_BYTES = 8_388_608;

    public function __invoke(ContainerInterface $container): Psr16ResponseCacheStore
    {
        $config = ContainerService::get($container, Config::class);
        $maxEntryBytes = $config->get(ConfigKey::MAX_ENTRY_BYTES, self::DEFAULT_MAX_ENTRY_BYTES);

        if (!is_int($maxEntryBytes) || $maxEntryBytes <= 0) {
            throw new RuntimeException(sprintf('%s config value must be a positive integer.', ConfigKey::MAX_ENTRY_BYTES));
        }

        return new Psr16ResponseCacheStore(
            cache: ContainerService::get($container, CacheInterface::class),
            maxEntryBytes: $maxEntryBytes,
        );
    }
}
