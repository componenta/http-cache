<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Header;
use Psr\Http\Message\ResponseInterface;

/** @internal */
final class ResponseMetadata
{
    public static function prepare(
        ResponseInterface $response,
        HttpCachePolicy $policy,
    ): ResponseInterface {
        if (!$response->hasHeader(Header::CACHE_CONTROL)) {
            $response = $response->withHeader(Header::CACHE_CONTROL, $policy->cacheControlValue());
        }
        if ($policy->private) {
            $response = self::forcePrivateCacheControl($response);
        }

        $vary = self::varyHeaders($response);

        foreach ($policy->varyHeaders as $header) {
            $vary[] = $header;
        }

        if ($policy->private) {
            $vary[] = strtolower(Header::AUTHORIZATION);
            $vary[] = strtolower(Header::COOKIE);
        }

        $vary = array_values(array_unique($vary));

        if ($vary !== []) {
            $response = $response->withHeader(Header::VARY, implode(', ', $vary));
        }

        return $response;
    }

    public static function protectPrivate(ResponseInterface $response, ?HttpCachePolicy $policy): ResponseInterface
    {
        // An explicit callback veto is not permission to change the origin response.
        if ($policy === null || !$policy->enabled || !$policy->private) {
            return $response;
        }

        $response = self::forcePrivateCacheControl($response);
        $vary = self::varyHeaders($response);
        if (!in_array('*', $vary, true)) {
            $vary[] = strtolower(Header::AUTHORIZATION);
            $vary[] = strtolower(Header::COOKIE);
            $response = $response->withHeader(Header::VARY, implode(', ', array_unique($vary)));
        }

        return $response;
    }

    private static function forcePrivateCacheControl(ResponseInterface $response): ResponseInterface
    {
        $parts = [];

        foreach (HeaderList::split($response->getHeader(Header::CACHE_CONTROL)) as $part) {
            $name = strtolower(trim(explode('=', $part, 2)[0]));

            if (!in_array($name, ['public', 'private', 's-maxage'], true)) {
                $parts[] = $part;
            }
        }

        array_unshift($parts, 'private');

        $response = $response->withHeader(Header::CACHE_CONTROL, implode(', ', $parts));
        foreach (['X-Accel-Expires', 'Surrogate-Control', 'CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $field) {
            if ($response->hasHeader($field)) {
                $response = $response->withoutHeader($field);
            }
        }

        return $response;
    }

    public static function weakEtag(ResponseInterface $response, string $contents): string
    {
        $context = hash_init('sha256');

        foreach ([
            Header::CONTENT_TYPE,
            Header::CONTENT_ENCODING,
            Header::CONTENT_LANGUAGE,
            Header::CONTENT_LOCATION,
            Header::CONTENT_DIGEST,
        ] as $header) {
            hash_update($context, strtolower($header) . "\0");
            foreach ($response->getHeader($header) as $value) {
                hash_update($context, $value . "\0");
            }
        }

        hash_update($context, $contents);

        return 'W/"' . hash_final($context) . '"';
    }

    /**
     * @return list<string>
     */
    public static function varyHeaders(ResponseInterface $response): array
    {
        $headers = [];

        foreach (HeaderList::split($response->getHeader(Header::VARY)) as $header) {
            $header = strtolower(trim($header));

            if ($header !== '' && !in_array($header, $headers, true)) {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    private function __construct() {}
}
