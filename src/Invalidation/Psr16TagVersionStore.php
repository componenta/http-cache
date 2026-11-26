<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

use InvalidArgumentException;
use Override;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

use function random_int;

final readonly class Psr16TagVersionStore implements TagVersionStoreInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'http-cache:tag',
    ) {}

    #[Override]
    public function versions(array $tags): array
    {
        $versions = [];
        $keys = $this->keysByTag($tags);

        if ($keys === []) {
            return [];
        }

        $values = [];
        foreach ($this->cache->getMultiple(array_keys($keys)) as $key => $value) {
            if (isset($keys[$key])) {
                $values[$key] = $value;
            }
        }

        foreach ($keys as $key => $aliases) {
            $version = $this->resolveVersion($key, $values[$key] ?? null);
            foreach ($aliases as $tag) {
                $versions[$tag] = $version;
            }
        }

        ksort($versions, SORT_STRING);

        return $versions;
    }

    #[Override]
    public function invalidateTags(array $tags): void
    {
        // Validate the complete input before changing any generations. A failure
        // for one key must not prevent best-effort invalidation of the others.
        $keys = $this->keysByTag($tags);
        $failure = null;
        foreach (array_keys($keys) as $key) {
            try {
                if (!$this->cache->set($key, $this->nextVersion($key))) {
                    throw new RuntimeException('Unable to invalidate HTTP cache tag.');
                }
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param array<array-key, mixed> $tags
     * @return array<string, list<string>>
     */
    private function keysByTag(array $tags): array
    {
        if (!array_is_list($tags)) {
            throw new InvalidArgumentException('HTTP cache tags must be a list.');
        }

        $keys = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new InvalidArgumentException('HTTP cache tags must be non-empty strings.');
            }

            $keys[$this->key(trim($tag))][] = $tag;
        }

        return $keys;
    }

    private function resolveVersion(string $key, mixed $version): int
    {
        if (is_int($version) && $version > 0) {
            return $version;
        }

        $replacement = $this->generateVersion();

        if (!$this->cache->set($key, $replacement)) {
            throw new RuntimeException('Unable to initialize HTTP cache tag generation.');
        }

        return $replacement;
    }

    private function key(string $tag): string
    {
        return hash('sha256', "tag\0" . strlen($this->prefix) . ':' . $this->prefix . $tag);
    }

    private function nextVersion(string $key): int
    {
        $current = $this->cache->get($key);

        do {
            $version = $this->generateVersion();
        } while ($version === $current);

        return $version;
    }

    private function generateVersion(): int
    {
        return random_int(1, PHP_INT_MAX);
    }
}
