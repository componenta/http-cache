<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use InvalidArgumentException;

final readonly class HttpCachePolicy
{
    /** @var list<string> */
    private(set) array $methods;

    /** @var list<int> */
    private(set) array $statuses;

    /** @var list<string> */
    private(set) array $varyHeaders;

    /** @var list<string> */
    private(set) array $tags;

    /**
     * @param list<string> $methods
     * @param list<int> $statuses
     * @param list<string> $varyHeaders
     * @param list<string> $tags
     */
    public function __construct(
        private(set) int $ttl,
        array $methods = ['GET', 'HEAD'],
        array $statuses = [200],
        array $varyHeaders = [],
        array $tags = [],
        private(set) bool $allowAuthenticated = false,
        private(set) bool $cacheSetCookie = false,
        private(set) bool $private = false,
        private(set) bool $generateEtag = true,
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('HTTP cache policy TTL must be greater than zero.');
        }

        $this->methods = self::normalizeMethods($methods);
        $this->statuses = self::normalizeStatuses($statuses);
        $this->varyHeaders = self::normalizeHeaderNames($varyHeaders);
        $this->tags = self::normalizeTags($tags);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            ttl: self::intValue($payload, 'ttl'),
            methods: self::stringList($payload, 'methods', ['GET', 'HEAD']),
            statuses: self::intList($payload, 'statuses', [200]),
            varyHeaders: self::stringList($payload, 'vary_headers', self::stringList($payload, 'varyHeaders', [])),
            tags: self::stringList($payload, 'tags', []),
            allowAuthenticated: self::boolValue($payload, 'allow_authenticated', self::boolValue($payload, 'allowAuthenticated', false)),
            cacheSetCookie: self::boolValue($payload, 'cache_set_cookie', self::boolValue($payload, 'cacheSetCookie', false)),
            private: self::boolValue($payload, 'private', false),
            generateEtag: self::boolValue($payload, 'generate_etag', self::boolValue($payload, 'generateEtag', true)),
        );
    }

    public function allowsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    public function allowsStatus(int $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    public function cacheControlValue(): string
    {
        $visibility = $this->private ? 'private' : 'public';

        return sprintf('%s, max-age=%d', $visibility, $this->ttl);
    }

    /**
     * @param list<string> $methods
     * @return list<string>
     */
    private static function normalizeMethods(array $methods): array
    {
        if ($methods === []) {
            throw new InvalidArgumentException('HTTP cache policy methods must not be empty.');
        }

        $normalized = [];

        foreach ($methods as $method) {
            if (!is_string($method) || trim($method) === '') {
                throw new InvalidArgumentException('HTTP cache policy methods must be non-empty strings.');
            }

            $method = strtoupper(trim($method));

            if (!in_array($method, $normalized, true)) {
                $normalized[] = $method;
            }
        }

        return $normalized;
    }

    /**
     * @param list<int> $statuses
     * @return list<int>
     */
    private static function normalizeStatuses(array $statuses): array
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('HTTP cache policy statuses must not be empty.');
        }

        $normalized = [];

        foreach ($statuses as $status) {
            if (!is_int($status) || $status < 100 || $status > 599) {
                throw new InvalidArgumentException('HTTP cache policy statuses must be valid HTTP status codes.');
            }

            if (!in_array($status, $normalized, true)) {
                $normalized[] = $status;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private static function normalizeHeaderNames(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $header) {
            if (!is_string($header) || trim($header) === '') {
                throw new InvalidArgumentException('HTTP cache policy vary headers must be non-empty strings.');
            }

            $header = strtolower(trim($header));

            if (!in_array($header, $normalized, true)) {
                $normalized[] = $header;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private static function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new InvalidArgumentException('HTTP cache policy tags must be non-empty strings.');
            }

            $tag = trim($tag);

            if (!in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function intValue(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('HTTP cache policy "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $default
     * @return list<string>
     */
    private static function stringList(array $payload, string $key, array $default): array
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }

        $value = $payload[$key];

        if (!is_array($value) || array_is_list($value) === false) {
            throw new InvalidArgumentException(sprintf('HTTP cache policy "%s" must be a list of strings.', $key));
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException(sprintf('HTTP cache policy "%s" must be a list of strings.', $key));
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int> $default
     * @return list<int>
     */
    private static function intList(array $payload, string $key, array $default): array
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }

        $value = $payload[$key];

        if (!is_array($value) || array_is_list($value) === false) {
            throw new InvalidArgumentException(sprintf('HTTP cache policy "%s" must be a list of integers.', $key));
        }

        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new InvalidArgumentException(sprintf('HTTP cache policy "%s" must be a list of integers.', $key));
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function boolValue(array $payload, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }

        $value = $payload[$key];

        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('HTTP cache policy "%s" must be a boolean.', $key));
        }

        return $value;
    }
}
