<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\CompositeCachePolicyProvider;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class CompositeCachePolicyProviderTest extends TestCase
{
    public function testFirstMatchingProviderWins(): void
    {
        $configured = new HttpCachePolicy(ttl: 60);
        $first = $this->createMock(CachePolicyProviderInterface::class);
        $first->expects(self::once())->method('policyFor')->willReturn($configured);
        $fallback = $this->createMock(CachePolicyProviderInterface::class);
        $fallback->expects(self::never())->method('policyFor');

        $provider = new CompositeCachePolicyProvider([$first, $fallback]);

        self::assertSame(
            $configured,
            $provider->policyFor(new ServerRequest('GET', 'https://example.test/articles')),
        );
    }

    public function testFallsBackWhenConfiguredProviderHasNoPolicy(): void
    {
        $attribute = new HttpCachePolicy(ttl: 90);
        $first = $this->createMock(CachePolicyProviderInterface::class);
        $first->expects(self::once())->method('policyFor')->willReturn(null);
        $fallback = $this->createMock(CachePolicyProviderInterface::class);
        $fallback->expects(self::once())->method('policyFor')->willReturn($attribute);

        $provider = new CompositeCachePolicyProvider([$first, $fallback]);

        self::assertSame(
            $attribute,
            $provider->policyFor(new ServerRequest('GET', 'https://example.test/articles')),
        );
    }
}
