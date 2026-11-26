<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

/** @internal */
final class CacheableStatus
{
    public static function allows(int $status): bool
    {
        // Partial and validation responses require unsupported storage semantics.
        // Proxy authentication and protocol upgrades require connection-specific
        // fields that this application cache cannot retain. RFC 6585 prohibits
        // caching 428, 429, 431 and 511.
        return $status >= 200 && $status <= 599
            && !in_array($status, [206, 304, 407, 426, 428, 429, 431, 511], true);
    }

    public static function forbidsContent(int $status): bool
    {
        return in_array($status, [204, 205], true);
    }

    private function __construct() {}
}
