<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Componenta\Http\Cache\Internal\CacheableStatus;
use Componenta\Http\Cache\Protocol\CacheControl;
use Componenta\Http\Cache\Protocol\HeaderList;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class CachedResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private(set) int $status,
        private(set) array $headers,
        private(set) string $body,
        private(set) int $storedAt,
        private(set) int $ageAtStore = 0,
        private(set) ?int $freshUntil = null,
    ) {}

    public function age(int $now): int
    {
        $residentTime = max(0, $now - $this->storedAt);

        if ($this->ageAtStore > PHP_INT_MAX - $residentTime) {
            return PHP_INT_MAX;
        }

        return $this->ageAtStore + $residentTime;
    }

    public function remainingFreshness(int $now): ?int
    {
        if ($this->freshUntil === null) {
            return null;
        }

        return max(0, $this->freshUntil - $now);
    }

    public function toResponse(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): ResponseInterface {
        $response = $responseFactory->createResponse($this->status);

        foreach ($this->headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($streamFactory->createStream($this->body));
    }

    /**
     * @return array{
     *     status:int,
     *     headers:array<string, list<string>>,
     *     body:string,
     *     storedAt:int,
     *     ageAtStore:int,
     *     freshUntil:?int
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'storedAt' => $this->storedAt,
            'ageAtStore' => $this->ageAtStore,
            'freshUntil' => $this->freshUntil,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $status = $payload['status'] ?? null;
        $headers = $payload['headers'] ?? null;
        $body = $payload['body'] ?? null;
        $storedAt = $payload['storedAt'] ?? null;
        $ageAtStore = $payload['ageAtStore'] ?? 0;
        $freshUntil = $payload['freshUntil'] ?? null;

        if (
            !is_int($status)
            || !CacheableStatus::allows($status)
            || !is_array($headers)
            || !is_string($body)
            || (CacheableStatus::forbidsContent($status) && $body !== '')
            || !is_int($storedAt)
            || $storedAt < 0
            || !is_int($ageAtStore)
            || $ageAtStore < 0
            || ($freshUntil !== null && (!is_int($freshUntil) || $freshUntil <= $storedAt))
        ) {
            return null;
        }

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

        $cacheControl = CacheControl::fromValues($normalized['cache-control'] ?? []);
        if (!$cacheControl->isValid() || $cacheControl->has('no-store') || $cacheControl->has('no-cache')
            || $cacheControl->has('must-understand')
            || in_array('*', HeaderList::split($normalized['vary'] ?? []), true)) {
            return null;
        }

        /** @var array<string, list<string>> $headers */
        return new self($status, $headers, $body, $storedAt, $ageAtStore, $freshUntil);
    }
}
