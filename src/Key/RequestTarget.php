<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Key;

use Psr\Http\Message\ServerRequestInterface;

final class RequestTarget
{
    /**
     * @return array{scheme:string,authority:string,target:string}
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
            'target' => $request->getRequestTarget(),
        ];
    }

    public static function cacheTag(ServerRequestInterface $request): string
    {
        return 'uri.' . hash('sha256', json_encode(self::identity($request), JSON_THROW_ON_ERROR));
    }

    private function __construct() {}
}
