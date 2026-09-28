<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Invalidation\TagVersionStoreInterface;
use Componenta\Http\Cache\Key\DefaultCacheKeyGenerator;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class DefaultCacheKeyGeneratorTest extends TestCase
{
    public function testTargetOriginParticipatesInCacheKey(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $policy = new HttpCachePolicy(ttl: 60);

        $shopA = $generator->generate(new ServerRequest('GET', 'https://shop-a.example/products/1'), $policy);
        $shopB = $generator->generate(new ServerRequest('GET', 'https://shop-b.example/products/1'), $policy);

        self::assertNotSame($shopA, $shopB);
    }

    public function testRawQueryStringParticipatesInCacheKeyWithoutPhpNormalization(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $policy = new HttpCachePolicy(ttl: 60);

        $dotted = $generator->generate(new ServerRequest('GET', 'https://example.test/items?a.b=1'), $policy);
        $underscored = $generator->generate(new ServerRequest('GET', 'https://example.test/items?a_b=1'), $policy);

        self::assertNotSame($dotted, $underscored);
    }

    public function testGeneratedKeyIsPortablePsr16Key(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags(), 'tenant:with:reserved:characters');
        $key = $generator->generate(
            new ServerRequest('GET', 'https://example.test/items?sort=name'),
            new HttpCachePolicy(ttl: 60),
        );

        self::assertSame(64, strlen($key));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
    }

    public function testConfiguredPrefixNamespacesKeys(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/items');
        $policy = new HttpCachePolicy(ttl: 60);

        $first = (new DefaultCacheKeyGenerator($this->tags(), 'first'))->generate($request, $policy);
        $second = (new DefaultCacheKeyGenerator($this->tags(), 'second'))->generate($request, $policy);

        self::assertNotSame($first, $second);
    }

    private function tags(): TagVersionStoreInterface
    {
        return new class implements TagVersionStoreInterface {
            public function versions(array $tags): array
            {
                return array_fill_keys($tags, 0);
            }

            public function invalidateTags(array $tags): void {}
        };
    }
}
