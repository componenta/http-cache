<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Componenta\Http\Cache\Protocol\CacheControl;
use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\HttpDate;
use Componenta\Http\Header;
use Psr\Http\Message\ResponseInterface;

/** @internal */
final class ResponseEligibility
{
    public static function ttl(
        ResponseInterface $response,
        HttpCachePolicy $policy,
        int $currentAge,
        float $responseTime,
        int $maxEntryBytes,
    ): ?int {
        if (!$policy->allowsStatus($response->getStatusCode())) {
            return null;
        }

        if (!self::hasSafeStorageMetadata($response)) {
            return null;
        }

        $size = $response->getBody()->getSize();

        if ($size !== null && $size > $maxEntryBytes) {
            return null;
        }

        $cacheControl = CacheControl::fromValues($response->getHeader(Header::CACHE_CONTROL));

        if ($cacheControl->has('no-store') || $cacheControl->has('no-cache') || $cacheControl->has('must-understand')) {
            return null;
        }

        if ($cacheControl->has('private') && !$policy->private) {
            return null;
        }

        if (!self::storageIsPermitted($response, $policy, $cacheControl)) {
            return null;
        }

        if (!self::varyIsCompatible($response->getHeader(Header::VARY), $policy)) {
            return null;
        }

        $ttl = $policy->ttl;
        $freshness = null;

        if (!$policy->private) {
            $freshness = $cacheControl->integer('s-maxage');
        }

        if ($freshness === null) {
            $freshness = $cacheControl->integer('max-age');
        }

        if ($freshness === false) {
            return null;
        }

        if (is_int($freshness)) {
            $remaining = $freshness - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = min($ttl, $remaining);
        } elseif ($response->hasHeader(Header::EXPIRES)) {
            $expires = HttpDate::parse($response->getHeaderLine(Header::EXPIRES), (int) $responseTime);
            $date = HttpDate::parse($response->getHeaderLine(Header::DATE), (int) $responseTime);

            if ($expires === null || $date === null || $expires <= $date) {
                return null;
            }

            $remaining = ($expires - $date) - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = min($ttl, $remaining);
        } else {
            $remaining = $policy->ttl - $currentAge;

            if ($remaining <= 0) {
                return null;
            }

            $ttl = $remaining;
        }

        return max(1, $ttl);
    }

    private static function storageIsPermitted(
        ResponseInterface $response,
        HttpCachePolicy $policy,
        CacheControl $cacheControl,
    ): bool {
        if (!$response->hasHeader(Header::CACHE_CONTROL) || $policy->private) {
            return true;
        }

        if (
            $cacheControl->has('public')
            || $cacheControl->has('max-age')
            || $response->hasHeader(Header::EXPIRES)
            || $cacheControl->has('s-maxage')
        ) {
            return true;
        }

        return in_array(
            $response->getStatusCode(),
            [200, 203, 204, 300, 301, 308, 404, 405, 410, 414, 501],
            true,
        );
    }

    /** @param array<string> $values */
    public static function varyIsCompatible(array $values, HttpCachePolicy $policy): bool
    {
        $vary = array_map(strtolower(...), HeaderList::split($values));

        if (in_array('*', $vary, true)) {
            return false;
        }

        $allowed = $policy->varyHeaders;

        if ($policy->private) {
            $allowed[] = strtolower(Header::AUTHORIZATION);
            $allowed[] = strtolower(Header::COOKIE);
        }

        foreach ($vary as $header) {
            if (!in_array($header, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    public static function hasSafeStorageMetadata(ResponseInterface $response): bool
    {
        foreach (['Set-Cookie', 'Content-Range', 'X-Accel-Redirect', 'X-Sendfile'] as $field) {
            if ($response->hasHeader($field)) {
                return false;
            }
        }

        // Connection-specific fields may be discarded, but never discard metadata
        // needed to determine cache safety or the identity of a representation.
        foreach (HeaderList::split($response->getHeader(Header::CONNECTION)) as $field) {
            if (in_array(strtolower($field), [
                'cache-control', 'age', 'date', 'expires', 'vary', 'etag',
                'last-modified', 'content-length', 'content-type', 'content-encoding',
                'content-language', 'content-location', 'content-digest',
            ], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string> $values */
    public static function matchesContentLength(array $values, int $length): bool
    {
        $values = array_values($values);
        if ($values === []) {
            return true;
        }
        if (count($values) !== 1 || preg_match('/^[0-9]+$/D', trim($values[0])) !== 1) {
            return false;
        }
        $normalized = ltrim(trim($values[0]), '0');

        return ($normalized === '' ? '0' : $normalized) === (string) $length;
    }

    private function __construct() {}
}
