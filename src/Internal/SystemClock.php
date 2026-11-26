<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** @internal */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
