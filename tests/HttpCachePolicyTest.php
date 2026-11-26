<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Policy\HttpCachePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpCachePolicyTest extends TestCase
{
    #[DataProvider('unsafeMethods')]
    public function testRejectsMethodsThatMustNotBeServedFromResponseCache(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, methods: [$method]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        yield 'post' => ['POST'];
        yield 'put' => ['PUT'];
        yield 'patch' => ['PATCH'];
        yield 'delete' => ['DELETE'];
        yield 'options' => ['OPTIONS'];
        yield 'trace' => ['TRACE'];
    }

    #[DataProvider('unsupportedStatuses')]
    public function testRejectsStatusesRequiringUnsupportedCacheSemantics(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, statuses: [$status]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsupportedStatuses(): iterable
    {
        yield 'informational' => [101];
        yield 'partial content' => [206];
        yield 'not modified' => [304];
        yield 'proxy authentication required' => [407];
        yield 'upgrade required' => [426];
        yield 'precondition required' => [428];
        yield 'too many requests' => [429];
        yield 'request header fields too large' => [431];
        yield 'network authentication required' => [511];
    }

    public function testUnknownConfigurationSettingCannotSilentlyEnableCaching(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HttpCachePolicy::fromArray(['ttl' => 60, 'enable' => false]);
    }

    public function testConflictingConfigurationAliasesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HttpCachePolicy::fromArray(['ttl' => 60, 'generateEtag' => false, 'generate_etag' => true]);
    }

    public function testEquivalentConfigurationAliasesRemainSupported(): void
    {
        $policy = HttpCachePolicy::fromArray([
            'ttl' => 60,
            'varyHeaders' => ['Accept-Language'],
            'vary_headers' => ['Accept-Language'],
            'private' => true,
            'allowAuthenticated' => true,
            'generate_etag' => false,
        ]);

        self::assertSame(['accept-language'], $policy->varyHeaders);
        self::assertTrue($policy->allowAuthenticated);
        self::assertTrue($policy->private);
        self::assertFalse($policy->generateEtag);
    }

    public function testWireMethodsAreCaseSensitive(): void
    {
        $policy = new HttpCachePolicy(ttl: 60);
        self::assertTrue($policy->allowsMethod('GET'));
        self::assertFalse($policy->allowsMethod('get'));
        self::assertFalse($policy->allowsMethod('head'));
    }

    public function testAuthenticatedCachingRequiresPrivatePolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, allowAuthenticated: true);
    }

    public function testPrivateCachingRequiresCredentialPartitioning(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, private: true);
    }

    public function testSetCookieCachingCannotBeEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, cacheSetCookie: true);
    }

    public function testRejectsWildcardVaryPolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, varyHeaders: ['*']);
    }

    public function testRejectsInvalidVaryFieldName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, varyHeaders: ["Accept-Language\r\nX-Injected: yes"]);
    }

    public function testAllowsCredentialPartitionedPrivateCaching(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true);

        self::assertTrue($policy->private);
        self::assertTrue($policy->allowAuthenticated);
        self::assertSame('private, max-age=60', $policy->cacheControlValue());
    }
}
