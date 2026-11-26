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

    public function testCacheControlRecognizesValidQuotedExtensionsAndMalformedSyntax(): void
    {
        self::assertTrue(CacheControl::fromValues(['max-age=60, extension="a,b"'])->isValid());
        self::assertTrue(CacheControl::fromValues(['extension="a\\"b"'])->isValid());
        self::assertFalse(CacheControl::fromValues(['extension="unclosed, no-store'])->isValid());
        self::assertFalse(CacheControl::fromValues(['max-age='])->isValid());
        self::assertFalse(CacheControl::fromValues(['invalid name=1'])->isValid());
    }

    public function testCacheControlRejectsArgumentsOnKnownArgumentlessDirectives(): void
    {
        foreach (['public', 'no-store', 'no-transform', 'only-if-cached', 'must-revalidate', 'proxy-revalidate', 'must-understand'] as $directive) {
            self::assertTrue(CacheControl::fromValues([$directive])->isValid(), $directive);
            self::assertFalse(CacheControl::fromValues([$directive . '=foo'])->isValid(), $directive);
            self::assertFalse(CacheControl::fromValues([$directive . '=""'])->isValid(), $directive);
        }
        // Qualified private/no-cache and unknown extension arguments remain legal.
        self::assertTrue(CacheControl::fromValues(['private="X-Private", no-cache="X-Test", extension=foo'])->isValid());
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
        self::assertIsInt($expected);

        self::assertSame($expected, HttpDate::parse('Sun, 06 Nov 1994 08:49:37 GMT'));
        self::assertSame($expected, HttpDate::parse('Sunday, 06-Nov-94 08:49:37 GMT', 1_788_000_000));
        self::assertSame($expected, HttpDate::parse('Sun Nov  6 08:49:37 1994'));
    }

    public function testObsoleteHttpDateUsesFullFiftyYearBoundary(): void
    {
        $now = (new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))->getTimestamp();
        $past = (new \DateTimeImmutable('1976-12-31T00:00:00+00:00'))->getTimestamp();
        $boundary = (new \DateTimeImmutable('2076-01-01T00:00:00+00:00'))->getTimestamp();

        self::assertSame($past, HttpDate::parse(gmdate('l, d-M-y H:i:s \G\M\T', $past), $now));
        self::assertSame($boundary, HttpDate::parse(gmdate('l, d-M-y H:i:s \G\M\T', $boundary), $now));
    }

    public function testObsoleteHttpDateCanResolveIntoNextCentury(): void
    {
        $now = (new \DateTimeImmutable('2090-01-01T00:00:00+00:00'))->getTimestamp();
        $future = (new \DateTimeImmutable('2105-06-01T00:00:00+00:00'))->getTimestamp();

        self::assertSame($future, HttpDate::parse(gmdate('l, d-M-y H:i:s \G\M\T', $future), $now));
    }

    public function testHttpDateDoesNotApplyPhpTwoDigitYearConversionToFourDigitYear(): void
    {
        self::assertNull(HttpDate::parse('Fri, 01 Jan 0099 00:00:00 GMT'));
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
            HttpDate::format((int) gmmktime(8, 49, 37, 11, 6, 1994)),
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

    public function testIfNoneMatchTreatsBackslashAsLiteralOpaqueTagCharacter(): void
    {
        self::assertTrue(EntityTag::ifNoneMatch(['"a\\", "other"'], 'W/"a\\"'));
        self::assertTrue(EntityTag::ifNoneMatch(['"a\\", "other"'], '"other"'));
        self::assertFalse(EntityTag::ifNoneMatch(['"a\\", "other"'], '"a"'));
    }

    public function testIfNoneMatchRejectsWildcardMixedWithOtherTags(): void
    {
        self::assertFalse(EntityTag::ifNoneMatch(['*, "abc"'], 'W/"abc"'));
    }

    public function testIfNoneMatchWildcardMustBeTheEntireFieldValue(): void
    {
        self::assertTrue(EntityTag::ifNoneMatch([" \t* \t"], null));
        foreach ([['*,'], [',*'], [',*,'], ['*', ''], ['', '*'], ['*', '*']] as $values) {
            self::assertFalse(EntityTag::ifNoneMatch($values, '"abc"'));
            self::assertFalse(EntityTag::ifNoneMatch($values, null));
        }
        // Empty list members are still tolerated for actual entity-tag lists.
        self::assertTrue(EntityTag::ifNoneMatch([', "abc",'], 'W/"abc"'));
        self::assertTrue(EntityTag::ifNoneMatch(['"*"'], '"*"'));
    }

    public function testIfNoneMatchRejectsMalformedTagList(): void
    {
        self::assertFalse(EntityTag::ifNoneMatch(['"abc", malformed'], 'W/"abc"'));
    }

    public function testIfNoneMatchWildcardMatchesStoredRepresentationWithoutEtag(): void
    {
        self::assertTrue(EntityTag::ifNoneMatch(['*'], null));
    }
}
