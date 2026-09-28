<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Invalidation;

use Override;
use Psr\SimpleCache\CacheInterface;
use Random\RandomException;
use RuntimeException;
use stdClass;

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
            if (!$this->cache->set($this->key($tag), $this->nextVersion($tag))) {
                throw new RuntimeException('Unable to invalidate HTTP cache tag.');
            }
        }
    }

    private function version(string $tag): int
    {
        $missing = new stdClass();
        $key = $this->key($tag);
        $version = $this->cache->get($key, $missing);

        if ($version === $missing) {
            return 0;
        }

        if (is_int($version) && $version >= 0) {
            return $version;
        }

        $replacement = $this->generateVersion();

        if (!$this->cache->set($key, $replacement)) {
            throw new RuntimeException('Unable to repair HTTP cache tag generation.');
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
        try {
            return random_int(1, PHP_INT_MAX);
        } catch (RandomException) {
            return max(1, hrtime(true));
        }
    }
}
