<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Store;

use InvalidArgumentException;

/**
 * Absolute freshness budget established when the origin response is received.
 * All timestamps and ages are expressed in seconds.
 */
final readonly class CacheFreshness
{
    public function __construct(
        public int $receivedAt,
        public int $ageAtReceipt,
        public int $freshUntil,
    ) {
        if ($receivedAt < 0 || $ageAtReceipt < 0 || $freshUntil <= $receivedAt) {
            throw new InvalidArgumentException('Invalid HTTP cache freshness budget.');
        }
    }

    public static function fromTtl(int $receivedAt, int $ttl, int $ageAtReceipt = 0): self
    {
        if ($receivedAt < 0 || $ttl <= 0) {
            throw new InvalidArgumentException('Invalid HTTP cache freshness TTL.');
        }
        $freshUntil = $ttl > PHP_INT_MAX - $receivedAt ? PHP_INT_MAX : $receivedAt + $ttl;

        return new self($receivedAt, $ageAtReceipt, $freshUntil);
    }

    public function remainingAt(int $now): int
    {
        return max(0, $this->freshUntil - max($now, $this->receivedAt));
    }

    public function ageAt(int $now): int
    {
        $elapsed = max($now, $this->receivedAt) - $this->receivedAt;

        return $this->ageAtReceipt > PHP_INT_MAX - $elapsed
            ? PHP_INT_MAX
            : $this->ageAtReceipt + $elapsed;
    }
}
