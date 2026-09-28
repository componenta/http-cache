<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Protocol\CacheControl;
use Componenta\Http\Cache\Protocol\EntityTag;
use Componenta\Http\Cache\Protocol\HeaderList;
use Componenta\Http\Cache\Protocol\HttpDate;
use Componenta\Http\Cache\Protocol\ResponseAge;
use Nyholm\Psr7\Response;
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

    public function testCacheControlAcceptsQuotedDeltaSeconds(): void
    {
        $cacheControl = CacheControl::fromValues(['max-age="60"']);

        self::assertSame(60, $cacheControl->integer('max-age'));
    }

    public function testCacheControlCapsUnrepresentableDeltaSeconds(): void
    {
        $cacheControl = CacheControl::fromValues(['max-age=999999999999999999999999']);

        self::assertSame(PHP_INT_MAX, $cacheControl->integer('max-age'));
    }

    public function testHttpDateAcceptsAllThreeRfcFormats(): void
    {
        $expected = gmmktime(8, 49, 37, 11, 6, 1994);

        self::assertSame($expected, HttpDate::parse('Sun, 06 Nov 1994 08:49:37 GMT'));
        self::assertSame($expected, HttpDate::parse('Sunday, 06-Nov-94 08:49:37 GMT', 1_788_000_000));
        self::assertSame($expected, HttpDate::parse('Sun Nov  6 08:49:37 1994'));
    }

    public function testHttpDateCacheParsingIsCaseInsensitive(): void
    {
        self::assertNotNull(HttpDate::parse('sun, 06 nov 1994 08:49:37 gmt'));
    }

    public function testHttpDateRejectsNonHttpDateAndWrongWeekday(): void
    {
        self::assertNull(HttpDate::parse('tomorrow'));
        self::assertNull(HttpDate::parse('Sun, 06 Nov 1994 08:49:37 UTC'));
        self::assertNull(HttpDate::parse('Mon, 06 Nov 1994 08:49:37 GMT'));
    }

    public function testHttpDateFormatsImfFixdateInUtc(): void
    {
        self::assertSame(
            'Sun, 06 Nov 1994 08:49:37 GMT',
            HttpDate::format(gmmktime(8, 49, 37, 11, 6, 1994)),
        );
    }

    public function testCorrectedInitialAgeIncludesResponseDelay(): void
    {
        $response = new Response(200, [
            'Date' => 'Thu, 01 Jan 1970 00:01:40 GMT',
            'Age' => '10',
        ]);

        self::assertSame(12, ResponseAge::correctedInitialAge($response, 100.0, 102.0));
    }

    public function testResponseAgeUsesFirstAgeFieldValue(): void
    {
        $response = new Response(200, ['Age' => ['5', '999']]);

        self::assertSame(5, ResponseAge::atReceipt($response, 102.0));
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
