<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Factory;

use Componenta\Config\Config;
use Componenta\Http\Cache\ConfigKey;
use Componenta\Http\Cache\Key\CacheKeyGeneratorInterface;
use Componenta\Http\Cache\Middleware\ResponseCacheMiddleware;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Store\ResponseCacheStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class ResponseCacheMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): ResponseCacheMiddleware
    {
        $config = $container->get(Config::class);

        return new ResponseCacheMiddleware(
            policies: $container->get(CachePolicyProviderInterface::class),
            keys: $container->get(CacheKeyGeneratorInterface::class),
            store: $container->get(ResponseCacheStoreInterface::class),
            responseFactory: $container->get(ResponseFactoryInterface::class),
            streamFactory: $container->get(StreamFactoryInterface::class),
            debugHeader: $config->bool(ConfigKey::DEBUG_HEADER, false),
        );
    }
}
