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
    }

    public function testAuthenticatedCachingRequiresPrivatePolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, allowAuthenticated: true);
    }

    public function testSetCookieCachingCannotBeEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, cacheSetCookie: true);
    }

    public function testRejectsInvalidVaryFieldName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, varyHeaders: ["Accept-Language\r\nX-Injected: yes"]);
    }

    public function testAllowsPrivateAuthenticatedCaching(): void
    {
        $policy = new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true);

        self::assertTrue($policy->private);
        self::assertTrue($policy->allowAuthenticated);
        self::assertSame('private, max-age=60', $policy->cacheControlValue());
    }
}
