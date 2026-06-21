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
    ) {}

    public function age(int $now): int
    {
        return max(0, $now - $this->storedAt);
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
     * @return array{status:int,headers:array<string, list<string>>,body:string,storedAt:int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'storedAt' => $this->storedAt,
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

        if (!is_int($status) || !is_array($headers) || !is_string($body) || !is_int($storedAt)) {
            return null;
        }

        foreach ($headers as $name => $values) {
            if (!is_string($name) || !is_array($values) || array_is_list($values) === false) {
                return null;
            }

            foreach ($values as $value) {
                if (!is_string($value)) {
                    return null;
                }
            }
        }

        /** @var array<string, list<string>> $headers */
        return new self($status, $headers, $body, $storedAt);
    }
}
