<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Attribute\CacheResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CacheResponseAttributeTest extends TestCase
{
    public function testAttributeUsesSamePolicyValidationAndNormalization(): void
    {
        $attribute = new CacheResponse(
            ttl: 60,
            methods: ['get'],
            varyHeaders: ['Accept-Language'],
        );

        self::assertSame(['GET'], $attribute->methods);
        self::assertSame(['accept-language'], $attribute->varyHeaders);
    }

    public function testAttributeRejectsUnsafeAuthenticatedPublicCaching(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheResponse(ttl: 60, allowAuthenticated: true);
    }
}
