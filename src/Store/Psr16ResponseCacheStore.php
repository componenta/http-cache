<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Componenta\Http\Cache\Protocol\HeaderList;
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

        return is_array($payload) ? CachedResponse::fromArray($payload) : null;
    }

    public function store(string $key, ResponseInterface $response, int $ttl): bool
    {
        if ($ttl <= 0 || $response->hasHeader('Set-Cookie') || $response->hasHeader('Content-Range')) {
            return false;
        }

        $contents = $this->readBody($response);

        if ($contents === null) {
            return false;
        }

        $cached = new CachedResponse(
            status: $response->getStatusCode(),
            headers: $this->storableHeaders($response),
            body: $contents,
            storedAt: time(),
            ageAtStore: $this->currentAge($response),
        );

        return $this->cache->set($key, $cached->toArray(), $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }

    private function readBody(ResponseInterface $response): ?string
    {
        $body = $response->getBody();

        if (!$body->isSeekable()) {
            return null;
        }

        $size = $body->getSize();

        if ($size !== null && $size > $this->maxEntryBytes) {
            return null;
        }

        $contents = '';
        $body->rewind();

        while (!$body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                break;
            }

            if (strlen($contents) + strlen($chunk) > $this->maxEntryBytes) {
                $body->rewind();

                return null;
            }

            $contents .= $chunk;
        }

        $body->rewind();

        return $contents;
    }

    /**
     * @return array<string, list<string>>
     */
    private function storableHeaders(ResponseInterface $response): array
    {
        $blocked = [
            'connection' => true,
            'keep-alive' => true,
            'proxy-authenticate' => true,
            'proxy-authorization' => true,
            'proxy-connection' => true,
            'te' => true,
            'trailer' => true,
            'transfer-encoding' => true,
            'upgrade' => true,
        ];

        foreach (HeaderList::split($response->getHeader('Connection')) as $name) {
            $blocked[strtolower($name)] = true;
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (!isset($blocked[strtolower($name)])) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

    private function currentAge(ResponseInterface $response): int
    {
        $age = $response->getHeaderLine('Age');
        $ageValue = preg_match('/^[0-9]+$/D', $age) === 1 ? min(PHP_INT_MAX, (int) $age) : 0;

        if (!$response->hasHeader('Date')) {
            return $ageValue;
        }

        $date = strtotime($response->getHeaderLine('Date'));

        return $date === false ? $ageValue : max($ageValue, max(0, time() - $date));
    }
}
