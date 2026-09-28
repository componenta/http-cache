<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

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
        return max(0, $this->ageAtStore + max(0, $now - $this->storedAt));
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
            || $status < 100
            || $status > 599
            || !is_array($headers)
            || !is_string($body)
            || !is_int($storedAt)
            || $storedAt < 0
            || !is_int($ageAtStore)
            || $ageAtStore < 0
            || ($freshUntil !== null && (!is_int($freshUntil) || $freshUntil < 0))
        ) {
            return null;
        }

        foreach ($headers as $name => $values) {
            if (
                !is_string($name)
                || preg_match("@^[!#$%&'*+.^_\\x60|~0-9A-Za-z-]+$@D", $name) !== 1
                || !is_array($values)
                || array_is_list($values) === false
            ) {
                return null;
            }

            foreach ($values as $value) {
                if (!is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
                    return null;
                }
            }
        }

        /** @var array<string, list<string>> $headers */
        return new self($status, $headers, $body, $storedAt, $ageAtStore, $freshUntil);
    }
}
