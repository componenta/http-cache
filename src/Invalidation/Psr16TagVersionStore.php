<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

use Override;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

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
        $keys = [];
        foreach (array_unique($tags) as $tag) {
            $keys[$this->key($tag)] = $tag;
        }

        if ($keys === []) {
            return [];
        }

        $values = [];
        foreach ($this->cache->getMultiple(array_keys($keys)) as $key => $value) {
            if (is_string($key) && isset($keys[$key])) {
                $values[$key] = $value;
            }
        }

        foreach ($keys as $key => $tag) {
            $versions[$tag] = $this->resolveVersion($key, $values[$key] ?? null);
        }

        ksort($versions);

        return $versions;
    }

    #[Override]
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!$this->cache->set($this->key($tag), $this->nextVersion($tag))) {
                throw new RuntimeException('Unable to invalidate HTTP cache tag.');
            }
        }
    }

    private function version(string $tag): int
    {
        $key = $this->key($tag);

        return $this->resolveVersion($key, $this->cache->get($key));
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
        return hash('sha256', $this->prefix . "\0" . $tag);
    }

    private function nextVersion(string $tag): int
    {
        $current = $this->version($tag);

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
