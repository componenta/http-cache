<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Key;

use Psr\Http\Message\ServerRequestInterface;

final class RequestTarget
{
    /**
     * @return array{scheme:string,authority:string,path:string,query:string,host:string,target:string}
     */
    public static function identity(ServerRequestInterface $request): array
    {
        $uri = $request->getUri();
        $host = strtolower($uri->getHost());
        $authority = $host;

        if ($uri->getPort() !== null) {
            $authority .= ':' . $uri->getPort();
        }

        if ($authority === '') {
            $authority = strtolower($request->getHeaderLine('Host'));
        }

        return [
            'scheme' => strtolower($uri->getScheme()),
            'authority' => $authority,
            'path' => $uri->getPath() === '' ? '/' : $uri->getPath(),
            'query' => $uri->getQuery(),
            'host' => $request->getHeaderLine('Host'),
            'target' => $request->getRequestTarget(),
        ];
    }

    public static function cacheTag(ServerRequestInterface $request): string
    {
        $identity = self::identity($request);
        // Invalidate every representation of the effective URI, independent of the
        // wire request target and Host header used to select that representation.
        unset($identity['target'], $identity['host']);

        return 'uri.' . hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function __construct() {}
}
