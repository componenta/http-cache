<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Componenta\Http\Cache\Protocol\EntityTag;
use Componenta\Http\Cache\Protocol\HttpDate;
use Componenta\Http\Cache\Store\CachedResponse;
use Componenta\Http\Header;
use Psr\Http\Message\ServerRequestInterface;

/** @internal */
final class ConditionalRequest
{
    public static function isNotModified(ServerRequestInterface $request, CachedResponse $cached, int $now): bool
    {
        if ($cached->status !== 200) {
            return false;
        }

        $headers = array_change_key_case($cached->headers, CASE_LOWER);
        $etag = $headers[strtolower(Header::ETAG)] ?? null;
        if ($request->hasHeader(Header::IF_NONE_MATCH)) {
            return EntityTag::ifNoneMatch($request->getHeader(Header::IF_NONE_MATCH), $etag === null ? null : implode(', ', $etag));
        }

        if (!$request->hasHeader(Header::IF_MODIFIED_SINCE)) {
            return false;
        }

        $condition = HttpDate::parse($request->getHeaderLine(Header::IF_MODIFIED_SINCE), $now);
        if ($condition === null) {
            return false;
        }

        $lastModified = $headers[strtolower(Header::LAST_MODIFIED)] ?? null;
        if ($lastModified !== null) {
            $validator = HttpDate::parse(implode(', ', $lastModified), $now);
            if ($validator === null) {
                return false;
            }
        } else {
            $date = $headers[strtolower(Header::DATE)] ?? null;
            $validator = $date === null
                ? $cached->storedAt
                : (HttpDate::parse(implode(', ', $date), $now) ?? $cached->storedAt);
        }

        return $validator <= $condition;
    }

    private function __construct() {}
}
