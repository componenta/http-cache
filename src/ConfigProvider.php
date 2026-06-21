<?php

declare(strict_types=1);

namespace Componenta\Http\Cache;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Http\Cache\Factory\ConfigCachePolicyProviderFactory;
use Componenta\Http\Cache\Factory\Psr16ResponseCacheStoreFactory;
use Componenta\Http\Cache\Factory\Psr16TagVersionStoreFactory;
use Componenta\Http\Cache\Factory\ResponseCacheMiddlewareFactory;
use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Invalidation\Psr16TagVersionStore;
use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Key\DefaultCacheKeyGenerator;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\ConfigCachePolicyProvider;
use Componenta\Http\Cache\Store\Psr16ResponseCacheStore;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Override;

final class ConfigProvider extends BaseConfigProvider
{
    #[Override]
    protected function getFactories(): array
    {
        return [
            ResponseCacheMiddleware::class => ResponseCacheMiddlewareFactory::class,
            ConfigCachePolicyProvider::class => ConfigCachePolicyProviderFactory::class,
            Psr16ResponseCacheStore::class => Psr16ResponseCacheStoreFactory::class,
            Psr16TagVersionStore::class => Psr16TagVersionStoreFactory::class,
        ];
    }

    #[Override]
    protected function getAutowires(): array
    {
        return [
            DefaultCacheKeyGenerator::class,
        ];
    }

    #[Override]
    protected function getAliases(): array
    {
        return [
            CachePolicyProviderInterface::class => ConfigCachePolicyProvider::class,
            CacheKeyGeneratorInterface::class => DefaultCacheKeyGenerator::class,
            ResponseCacheStoreInterface::class => Psr16ResponseCacheStore::class,
            TagVersionStoreInterface::class => Psr16TagVersionStore::class,
            CacheInvalidatorInterface::class => Psr16TagVersionStore::class,
        ];
    }

    #[Override]
    protected function getConfig(): array
    {
        return [
            ConfigKey::POLICIES => [],
            ConfigKey::DEBUG_HEADER => false,
            ConfigKey::KEY_PREFIX => 'http-cache',
        ];
    }
}
