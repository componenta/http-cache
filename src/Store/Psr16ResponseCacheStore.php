<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\ResponseAge;
use Componenta\Http\Header;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class Psr16ResponseCacheStore implements ResponseCacheStoreInterface
{
    private const int DEFAULT_MAX_ENTRY_BYTES = 8_388_608;

    public function __construct(
        private CacheInterface $cache,
        private int $maxEntryBytes = self::DEFAULT_MAX_ENTRY_BYTES,
    ) {
        if ($maxEntryBytes <= 0) {
            throw new InvalidArgumentException('HTTP cache maximum entry size must be greater than zero.');
        }
    }

    public function fetch(string $key): ?CachedResponse
    {
        $payload = $this->cache->get($key);

        if (!is_array($payload) || !self::hasStringKeys($payload)) {
            return null;
        }

        $cached = CachedResponse::fromArray($payload);

        if ($cached === null) {
            return null;
        }

        if (strlen($cached->body) + $this->headerBytes($cached->headers) > $this->maxEntryBytes) {
            return null;
        }

        return $cached;
    }

    public function store(string $key, ResponseInterface $response, int $ttl): bool
    {
        $status = $response->getStatusCode();

        if (
            $ttl <= 0
            || $status < 200
            || in_array($status, [206, 304], true)
            || $response->hasHeader(Header::SET_COOKIE)
            || $response->hasHeader(Header::CONTENT_RANGE)
        ) {
            return false;
        }

        $headers = $this->storableHeaders($response);
        $headerBytes = $this->headerBytes($headers);

        if ($headerBytes >= $this->maxEntryBytes) {
            return false;
        }

        $contents = $this->readBody($response, $this->maxEntryBytes - $headerBytes);

        if ($contents === null) {
            return false;
        }

        $storedAt = time();
        $freshUntil = $ttl > PHP_INT_MAX - $storedAt
            ? PHP_INT_MAX
            : $storedAt + $ttl;

        $cached = new CachedResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: $contents,
            storedAt: $storedAt,
            ageAtStore: ResponseAge::atReceipt($response),
            freshUntil: $freshUntil,
        );

        return $this->cache->set($key, $cached->toArray(), $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }

    private function readBody(ResponseInterface $response, int $maxBytes): ?string
    {
        $body = $response->getBody();

        if (!$body->isSeekable() || !$body->isReadable()) {
            return null;
        }

        $size = $body->getSize();

        if ($size !== null && $size > $maxBytes) {
            return null;
        }

        $position = $body->tell();
        $contents = '';

        try {
            $body->rewind();

            while (!$body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    break;
                }

                if (strlen($contents) + strlen($chunk) > $maxBytes) {
                    return null;
                }

                $contents .= $chunk;
            }

            return $contents;
        } finally {
            $body->seek($position);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function storableHeaders(ResponseInterface $response): array
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
            if (!isset($blocked[strtolower($name)])) {
                $headers[$name] = array_values($values);
            }
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function hasStringKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function headerBytes(array $headers): int
    {
        $bytes = 0;

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + strlen($value) + 4;

                if ($bytes >= $this->maxEntryBytes) {
                    return $bytes;
                }
            }
        }

        return $bytes;
    }
}
