<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

/** @internal */
final class CacheableStatus
{
    public static function allows(int $status): bool
    {
        // Partial and validation responses require storage semantics not implemented
        // by this cache. RFC 6585 additionally prohibits these four error statuses.
        return $status >= 200 && $status <= 599
            && !in_array($status, [206, 304, 428, 429, 431, 511], true);
    }

    public static function forbidsContent(int $status): bool
    {
        return in_array($status, [204, 205], true);
    }

    private function __construct() {}
}
