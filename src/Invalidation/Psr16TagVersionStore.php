<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

use Override;
use Psr\SimpleCache\CacheInterface;
use Random\RandomException;

use function hrtime;
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

        foreach ($tags as $tag) {
            $versions[$tag] = $this->version($tag);
        }

        ksort($versions);

        return $versions;
    }

    #[Override]
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->cache->set($this->key($tag), $this->nextVersion($tag));
        }
    }

    private function version(string $tag): int
    {
        $version = $this->cache->get($this->key($tag), 0);

        return is_int($version) ? $version : 0;
    }

    private function key(string $tag): string
    {
        return $this->prefix . ':' . hash('xxh128', $tag);
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
        try {
            return random_int(1, PHP_INT_MAX);
        } catch (RandomException) {
            return max(1, hrtime(true));
        }
    }
}
