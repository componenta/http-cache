<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

/** @internal */
final class StoredHeaders
{
    /**
     * Validate stored end-to-end fields and return a case-insensitive index.
     * Never merge differently cased duplicate fields.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, list<string>>|null
     */
    public static function normalize(array $headers): ?array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            if (
                !is_string($name)
                || preg_match("@^[!#$%&'*+.^_\\x60|~0-9A-Za-z-]+$@D", $name) !== 1
                || !is_array($values)
                || array_is_list($values) === false
            ) {
                return null;
            }

            $lowerName = strtolower($name);
            if (isset($normalized[$lowerName]) || in_array($lowerName, [
                'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authentication-info',
                'proxy-authorization', 'proxy-connection', 'te', 'trailer',
                'transfer-encoding', 'upgrade', 'set-cookie', 'content-range', 'x-accel-redirect', 'x-sendfile',
            ], true) || $values === []) {
                return null;
            }

            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/[\\x00-\\x08\\x0A-\\x1F\\x7F]/', $value) === 1) {
                    return null;
                }
            }
            /** @var list<string> $values */
            $normalized[$lowerName] = $values;
        }

        return $normalized;
    }

    private function __construct() {}
}
