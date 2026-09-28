<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Internal\ContainerService;
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
        $config = ContainerService::get($container, Config::class);
        $maxEntryBytes = $config->get(ConfigKey::MAX_ENTRY_BYTES, self::DEFAULT_MAX_ENTRY_BYTES);

        if (!is_int($maxEntryBytes) || $maxEntryBytes <= 0) {
            throw new RuntimeException(sprintf('%s config value must be a positive integer.', ConfigKey::MAX_ENTRY_BYTES));
        }

        $logger = $container->has(LoggerInterface::class)
            ? ContainerService::get($container, LoggerInterface::class)
            : null;

        return new ResponseCacheMiddleware(
            policies: ContainerService::get($container, CachePolicyProviderInterface::class),
            keys: ContainerService::get($container, CacheKeyGeneratorInterface::class),
            store: ContainerService::get($container, ResponseCacheStoreInterface::class),
            invalidator: ContainerService::get($container, CacheInvalidatorInterface::class),
            responseFactory: ContainerService::get($container, ResponseFactoryInterface::class),
            streamFactory: ContainerService::get($container, StreamFactoryInterface::class),
            debugHeader: $config->bool(ConfigKey::DEBUG_HEADER, false),
            maxEntryBytes: $maxEntryBytes,
            logger: $logger,
        );
    }
}
