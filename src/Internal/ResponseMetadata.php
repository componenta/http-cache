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
final class ResponseMetadata
{
    public static function prepare(
        ResponseInterface $response,
        HttpCachePolicy $policy,
        int $now,
    ): ResponseInterface {
        if (!$response->hasHeader(Header::CACHE_CONTROL)) {
            $maxAge = $policy->ttl;
            if ($response->hasHeader(Header::EXPIRES)) {
                $expires = HttpDate::parse($response->getHeaderLine(Header::EXPIRES), $now);
                $date = HttpDate::parse($response->getHeaderLine(Header::DATE), $now);
                $maxAge = $expires === null || $date === null ? 0 : min($maxAge, max(0, $expires - $date));
            }
            $response = $response->withHeader(
                Header::CACHE_CONTROL,
                sprintf('%s, max-age=%d', $policy->private ? 'private' : 'public', $maxAge),
            );
        }
        return self::protectPolicy($response, $policy);
    }

    public static function protectPolicy(ResponseInterface $response, ?HttpCachePolicy $policy): ResponseInterface
    {
        // A veto or absent policy must leave the origin response untouched.
        if ($policy === null || !$policy->enabled) {
            return $response;
        }

        $required = $policy->varyHeaders;
        if ($policy->private) {
            $response = self::forcePrivateCacheControl($response);
            $required[] = strtolower(Header::AUTHORIZATION);
            $required[] = strtolower(Header::COOKIE);
        }

        // Representation selection remains relevant to downstream caches even
        // when local storage is unavailable or the request bypasses this cache.
        if ($required === []) {
            return $response;
        }

        $vary = self::varyHeaders($response);
        if (!in_array('*', $vary, true)) {
            $response = $response->withHeader(
                Header::VARY,
                implode(', ', array_unique([...$vary, ...$required])),
            );
        }

        return self::preserveEndToEndFields(
            $response,
            $policy->private ? ['cache-control', 'vary'] : ['vary'],
        );
    }

    /** @param list<string> $protected */
    private static function preserveEndToEndFields(ResponseInterface $response, array $protected): ResponseInterface
    {
        // A Connection option must not instruct an intermediary to remove
        // the end-to-end fields that enforce the selected cache policy.
        if (!$response->hasHeader(Header::CONNECTION)) {
            return $response;
        }

        $connection = HeaderList::split($response->getHeader(Header::CONNECTION));
        $retained = array_values(array_filter(
            $connection,
            static fn (string $name): bool => !in_array(strtolower($name), $protected, true),
        ));

        if ($retained === $connection) {
            return $response;
        }

        return $retained === []
            ? $response->withoutHeader(Header::CONNECTION)
            : $response->withHeader(Header::CONNECTION, implode(', ', $retained));
    }

    private static function forcePrivateCacheControl(ResponseInterface $response): ResponseInterface
    {
        $parts = [];
        $values = $response->getHeader(Header::CACHE_CONTROL);
        if (!CacheControl::fromValues($values)->isValid()) {
            // An invalid origin field cannot be safely rewritten directive by
            // directive. Keep the outgoing response strictly private.
            $values = ['no-store'];
        }

        foreach (HeaderList::split($values) as $part) {
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
        hash_update($context, "componenta-http-cache-etag-v2\0");

        foreach ([
            Header::CONTENT_TYPE,
            Header::CONTENT_ENCODING,
            Header::CONTENT_LANGUAGE,
            Header::CONTENT_LOCATION,
            Header::CONTENT_DIGEST,
        ] as $header) {
            // Fixed field order, explicit value counts and byte lengths make
            // the representation unambiguous even for binary content.
            $values = $response->getHeader($header);
            hash_update($context, count($values) . ':');
            foreach ($values as $value) {
                hash_update($context, strlen($value) . ':');
                hash_update($context, $value);
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
