<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use Componenta\Http\Cache\Internal\CacheableStatus;
use Componenta\Http\Cache\Internal\ResponseEligibility;
use Componenta\Http\Cache\Internal\StoredHeaders;
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
        // A factory may have application defaults. A cache hit must reconstruct
        // the saved representation rather than merge unrelated default fields.
        foreach ($response->getHeaders() as $name => $_) {
            $response = $response->withoutHeader((string) $name);
        }

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

        $normalized = StoredHeaders::normalize($headers);
        if ($normalized === null || !ResponseEligibility::hasRequiredStatusFields($status, $normalized)) {
            return null;
        }

        $lengths = $normalized['content-length'] ?? [];
        if (!ResponseEligibility::validContentFraming($status, $lengths)
            || ($body !== '' && !ResponseEligibility::matchesContentLength($lengths, strlen($body)))) {
            return null;
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
