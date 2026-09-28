<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Psr\Container\ContainerInterface;
use RuntimeException;

final class ContainerService
{
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public static function get(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);

        if (!$service instanceof $id) {
            throw new RuntimeException(sprintf(
                'Container service "%s" must be an instance of %s; got %s.',
                $id,
                $id,
                get_debug_type($service),
            ));
        }

        return $service;
    }

    private function __construct() {}
}
