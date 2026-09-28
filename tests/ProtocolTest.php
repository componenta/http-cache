<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Protocol\CacheControl;
use Componenta\Http\Cache\Protocol\EntityTag;
use Componenta\Http\Cache\Protocol\HeaderList;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    public function testHeaderListDoesNotSplitQuotedComma(): void
    {
        self::assertSame(
            ['no-cache="Set-Cookie, Authorization"', 'max-age=60'],
            HeaderList::split(['no-cache="Set-Cookie, Authorization", max-age=60']),
        );
    }

    public function testCacheControlRejectsConflictingNumericDirective(): void
    {
        $cacheControl = CacheControl::fromValues(['max-age=60, max-age=120']);

        self::assertFalse($cacheControl->integer('max-age'));
    }

    public function testIfNoneMatchUsesWeakComparison(): void
    {
        self::assertTrue(EntityTag::ifNoneMatch(['"abc"'], 'W/"abc"'));
        self::assertTrue(EntityTag::ifNoneMatch(['W/"abc"'], '"abc"'));
    }

    public function testIfNoneMatchParserSupportsCommaInsideOpaqueTag(): void
    {
        self::assertTrue(EntityTag::ifNoneMatch(['"a,b", "other"'], 'W/"a,b"'));
    }

    public function testIfNoneMatchWildcardMatchesStoredRepresentationWithoutEtag(): void
    {
        self::assertTrue(EntityTag::ifNoneMatch(['*'], null));
    }
}
