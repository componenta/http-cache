<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests\Fixture;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(int $seconds): void
    {
        $this->current = $this->current->modify(sprintf('+%d seconds', $seconds));
    }
}
