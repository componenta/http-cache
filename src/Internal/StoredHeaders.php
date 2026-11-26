<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\HttpDate;
use Componenta\Http\Header;
use Psr\Http\Message\ResponseInterface;

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

    /** @param array<string, list<string>> $headers Lowercase field names. */
    public static function hasValidDate(array $headers, int $referenceTime): bool
    {
        return isset($headers['date']) && HttpDate::parse(implode(', ', $headers['date']), $referenceTime) !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function forStorage(ResponseInterface $response): array
    {
        $blocked = [
            strtolower(Header::CONNECTION) => true,
            strtolower(Header::KEEP_ALIVE) => true,
            strtolower(Header::PROXY_AUTHENTICATE) => true,
            'proxy-authentication-info' => true,
            strtolower(Header::PROXY_AUTHORIZATION) => true,
            'proxy-connection' => true,
            strtolower(Header::TE) => true,
            strtolower(Header::TRAILER) => true,
            strtolower(Header::TRANSFER_ENCODING) => true,
            strtolower(Header::UPGRADE) => true,
        ];

        foreach (HeaderList::split(array_values($response->getHeader(Header::CONNECTION))) as $name) {
            $blocked[strtolower($name)] = true;
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (!is_string($name) || isset($blocked[strtolower($name)])) {
                continue;
            }

            $headers[$name] = array_values($values);
        }

        return $headers;
    }

    private function __construct() {}
}
