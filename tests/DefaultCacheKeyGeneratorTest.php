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

    public function testEffectiveUriAndExplicitRequestTargetBothPartitionKeys(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $policy = new HttpCachePolicy(ttl: 60);
        $first = (new ServerRequest('GET', 'https://example.test/first?a=1'))->withRequestTarget('/wire');
        $second = (new ServerRequest('GET', 'https://example.test/second?a=2'))->withRequestTarget('/wire');

        self::assertNotSame($generator->generate($first, $policy), $generator->generate($second, $policy));
        self::assertNotSame($generator->generate($first, $policy), $generator->generate($first->withRequestTarget('/other'), $policy));
        self::assertSame(
            \Componenta\Http\Cache\Key\RequestTarget::cacheTag($first),
            \Componenta\Http\Cache\Key\RequestTarget::cacheTag($first->withRequestTarget('/other')->withMethod('POST')),
        );
    }

    public function testHostHeaderAndCaseSensitiveMethodPartitionKeys(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $policy = new HttpCachePolicy(ttl: 60);
        $request = new ServerRequest('GET', 'https://example.test/items');

        self::assertNotSame($generator->generate($request, $policy), $generator->generate($request->withHeader('Host', 'other.test'), $policy));
        self::assertNotSame($generator->generate($request, $policy), $generator->generate($request->withMethod('get'), $policy));
    }

    public function testParsedQueryParametersPartitionIdenticalWireUris(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $policy = new HttpCachePolicy(ttl: 60);
        $request = new ServerRequest('GET', 'https://example.test/items?category=1');

        self::assertNotSame(
            $generator->generate($request->withQueryParams(['category' => '1']), $policy),
            $generator->generate($request->withQueryParams(['category' => '2']), $policy),
        );
    }

    public function testParsedCookieParametersPartitionIdenticalWireCredentials(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $policy = new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true);
        $request = (new ServerRequest('GET', 'https://example.test/account'))->withHeader('Cookie', 'session=token');

        self::assertNotSame(
            $generator->generate($request->withCookieParams(['session' => 'first']), $policy),
            $generator->generate($request->withCookieParams(['session' => 'second']), $policy),
        );
    }

    public function testKeyRejectsExecutableParameterObjects(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $request = (new ServerRequest('GET', 'https://example.test/items'))->withQueryParams(['value' => new \stdClass()]);

        $this->expectException(\InvalidArgumentException::class);
        $generator->generate($request, new HttpCachePolicy(ttl: 60));
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

    public function testPolicyChangesCreateNewCacheNamespace(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $request = new ServerRequest('GET', 'https://example.test/items');

        $short = $generator->generate($request, new HttpCachePolicy(ttl: 60));
        $long = $generator->generate($request, new HttpCachePolicy(ttl: 600));

        self::assertNotSame($short, $long);
    }

    public function testPrivateCredentialVariantDoesNotSharePublicKey(): void
    {
        $generator = new DefaultCacheKeyGenerator($this->tags());
        $request = (new ServerRequest('GET', 'https://example.test/items'))
            ->withHeader('Authorization', 'Bearer secret');

        $private = $generator->generate(
            $request,
            new HttpCachePolicy(ttl: 60, private: true, allowAuthenticated: true),
        );
        $public = $generator->generate(
            new ServerRequest('GET', 'https://example.test/items'),
            new HttpCachePolicy(ttl: 60),
        );

        self::assertNotSame($private, $public);
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
