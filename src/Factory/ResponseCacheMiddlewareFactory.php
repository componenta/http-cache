<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Invalidation\CacheInvalidatorInterface;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class ResponseCacheMiddlewareFactory
{
    private const int DEFAULT_MAX_ENTRY_BYTES = 8_388_608;

    public function __invoke(ContainerInterface $container): ResponseCacheMiddleware
    {
        $config = $container->get(Config::class);
        $maxEntryBytes = $config->get(ConfigKey::MAX_ENTRY_BYTES, self::DEFAULT_MAX_ENTRY_BYTES);

        if (!is_int($maxEntryBytes) || $maxEntryBytes <= 0) {
            throw new RuntimeException(sprintf('%s config value must be a positive integer.', ConfigKey::MAX_ENTRY_BYTES));
        }

        return new ResponseCacheMiddleware(
            policies: $container->get(CachePolicyProviderInterface::class),
            keys: $container->get(CacheKeyGeneratorInterface::class),
            store: $container->get(ResponseCacheStoreInterface::class),
            invalidator: $container->get(CacheInvalidatorInterface::class),
            responseFactory: $container->get(ResponseFactoryInterface::class),
            streamFactory: $container->get(StreamFactoryInterface::class),
            debugHeader: $config->bool(ConfigKey::DEBUG_HEADER, false),
            maxEntryBytes: $maxEntryBytes,
            logger: $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );
    }
}
