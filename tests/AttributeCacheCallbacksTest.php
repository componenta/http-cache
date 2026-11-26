<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Closure;
use Componenta\Http\Cache\Attribute\CacheResponse;
use Componenta\Http\Cache\Policy\AttributeCachePolicyProvider;
use Componenta\Http\Cache\Policy\CachePolicyProviderInterface;
use Componenta\Http\Cache\Policy\CompositeCachePolicyProvider;
use Componenta\Http\Cache\Policy\ConfigCachePolicyProvider;
use Componenta\Http\Cache\Tests\Fixture\CacheRequest;
use Componenta\Http\Cache\Tests\Fixture\CallbackCachedHandler;
use Componenta\Http\Cache\Tests\Fixture\CallbackProbe;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

final class AttributeCacheCallbacksTest extends TestCase
{
    public function testNativeAnonymousCallbackIsNotExecutedDuringAttributeConstruction(): void
    {
        $attribute = (new ReflectionClass(InlineCacheCallbackHandler::class))
            ->getAttributes(CacheResponse::class)[0]->newInstance();
        $request = CacheRequest::forHandler(InlineCacheCallbackHandler::class);
        $probe = new CallbackProbe();

        self::assertSame([], $probe->calls);
        $policy = $attribute->toPolicy($request->withAttribute('probe', $probe));

        self::assertTrue($policy->enabled);
        self::assertSame(['inline'], $probe->calls);
    }

    public function testMethodVetoOverridesClassAndStopsCompositeFallback(): void
    {
        $fallback = $this->createMock(CachePolicyProviderInterface::class);
        $fallback->expects(self::never())->method('policyFor');
        $provider = new CompositeCachePolicyProvider([new AttributeCachePolicyProvider(), $fallback]);

        $policy = $provider->policyFor(CacheRequest::forHandler([MethodVetoCacheHandler::class, 'show']));

        self::assertNotNull($policy);
        self::assertFalse($policy->enabled);
        self::assertSame(30, $policy->ttl);
    }

    public function testConfiguredPolicyStillPrecedesAttributeEvaluation(): void
    {
        $probe = new CallbackProbe();
        $provider = new CompositeCachePolicyProvider([
            new ConfigCachePolicyProvider(['catalog' => ['ttl' => 120, 'enabled' => false]]),
            new AttributeCachePolicyProvider(),
        ]);

        $policy = $provider->policyFor(CacheRequest::forHandler(CallbackCachedHandler::class)->withAttribute('probe', $probe));

        self::assertNotNull($policy);
        self::assertFalse($policy->enabled);
        self::assertSame(120, $policy->ttl);
        self::assertSame([], $probe->calls);
    }

    public function testSharedTraitFirstClassCallableUsesEachPrivateClassScopeRepeatedly(): void
    {
        $provider = new AttributeCachePolicyProvider();
        foreach ([FirstTraitCacheHandler::class, SecondTraitCacheHandler::class, FirstTraitCacheHandler::class, SecondTraitCacheHandler::class] as $class) {
            $request = CacheRequest::forHandler($class);
            $policy = $provider->policyFor($request);
            self::assertNotNull($policy);
            self::assertSame([$class], $policy->tags);
            self::assertSame((new $class())($request), $policy->tags);
        }
    }

    public function testNativePrivateVisibilityErrorIsPreserved(): void
    {
        $this->expectException(Error::class);

        (new AttributeCachePolicyProvider())->policyFor(CacheRequest::forHandler(InaccessibleCacheCallbackHandler::class));
    }
}

#[CacheResponse(ttl: 60, when: static function (ServerRequestInterface $request): bool {
    $probe = $request->getAttribute('probe');
    if ($probe instanceof CallbackProbe) {
        $probe->calls[] = 'inline';
    }

    return true;
})]
final class InlineCacheCallbackHandler
{
    public function __invoke(): void {}
}

#[CacheResponse(ttl: 120)]
final class MethodVetoCacheHandler
{
    #[CacheResponse(ttl: 30, when: static function (ServerRequestInterface $request): bool { return false; })]
    public static function show(): void {}
}

trait SharedCacheCallback
{
    private const Closure TAGS = self::tags(...);
}

#[CacheResponse(ttl: 60, tagsFactory: self::TAGS)]
final class FirstTraitCacheHandler
{
    use SharedCacheCallback;

    /** @return list<string> */
    public function __invoke(ServerRequestInterface $request): array
    {
        return self::tags($request);
    }

    /** @return list<string> */
    private static function tags(ServerRequestInterface $request): array
    {
        return [self::class];
    }
}

#[CacheResponse(ttl: 60, tagsFactory: self::TAGS)]
final class SecondTraitCacheHandler
{
    use SharedCacheCallback;

    /** @return list<string> */
    public function __invoke(ServerRequestInterface $request): array
    {
        return self::tags($request);
    }

    /** @return list<string> */
    private static function tags(ServerRequestInterface $request): array
    {
        return [self::class];
    }
}

#[CacheResponse(ttl: 60, tagsFactory: FirstTraitCacheHandler::tags(...))] // @phpstan-ignore staticMethod.private
final class InaccessibleCacheCallbackHandler
{
    public function __invoke(): void {}
}
