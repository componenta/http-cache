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
        if (!CacheableStatus::allows($response->getStatusCode()) || !$policy->allowsStatus($response->getStatusCode())) {
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

        if (!$cacheControl->isValid() || $cacheControl->has('no-store') || $cacheControl->has('no-cache') || $cacheControl->has('must-understand')) {
            return null;
        }

        if ($cacheControl->has('private') && !$policy->private) {
            return null;
        }

        // An absent Cache-Control field will be made explicitly cacheable
        // by prepare(); an existing field must already allow shared storage.
        if (!$policy->private && $response->hasHeader(Header::CACHE_CONTROL)
            && !self::hasSharedStoragePermission(
                $response->getStatusCode(),
                $cacheControl,
                $response->hasHeader(Header::EXPIRES),
            )) {
            return null;
        }

        if (!self::varyIsCompatible($response->getHeader(Header::VARY), $policy)) {
            return null;
        }

        // If no explicit Cache-Control exists, prepare() will emit a policy
        // max-age. The cache must not outlive the header it synthesizes.
        $ttl = $response->hasHeader(Header::CACHE_CONTROL)
            ? $policy->ttl
            : $policy->ttl - $currentAge;
        if ($ttl <= 0) {
            return null;
        }
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

    public static function hasSharedStoragePermission(
        int $status,
        CacheControl $cacheControl,
        bool $hasExpires,
    ): bool {
        if (
            $cacheControl->has('public')
            || $cacheControl->has('max-age')
            || $hasExpires
            || $cacheControl->has('s-maxage')
        ) {
            return true;
        }

        return in_array(
            $status,
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
        // Transfer codings and trailers belong to the transport. A PSR-7
        // stream alone cannot prove that all codings were decoded or that
        // trailer metadata has been incorporated into the representation.
        foreach (['Set-Cookie', 'Content-Range', 'X-Accel-Redirect', 'X-Sendfile', 'Transfer-Encoding', 'Trailer'] as $field) {
            if ($response->hasHeader($field)) {
                return false;
            }
        }

        if (!self::validContentFraming(
            $response->getStatusCode(),
            $response->getHeader(Header::CONTENT_LENGTH),
        )) {
            return false;
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

    /** @param array<string> $lengths */
    public static function validContentFraming(int $status, array $lengths): bool
    {
        $length = self::contentLength($lengths);
        if ($length === false) {
            return false;
        }

        // RFC 9110 §8.6 prohibits Content-Length for 204.
        // HEAD may otherwise advertise the length of the selected representation.
        if ($status === 204) {
            return $length === null;
        }

        return $status !== 205 || $length === null || $length === '0';
    }

    /** @param array<string> $values */
    public static function matchesContentLength(array $values, int $length): bool
    {
        $declared = self::contentLength($values);

        return $declared === null || $declared === (string) $length;
    }

    /** @param array<string> $values */
    private static function contentLength(array $values): string|false|null
    {
        if ($values === []) {
            return null;
        }
        $values = array_values($values);
        if (count($values) !== 1) {
            return false;
        }
        $value = trim($values[0], " \t");
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return false;
        }
        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private function __construct() {}
}
