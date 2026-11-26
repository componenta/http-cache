<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests;

use Componenta\Http\Cache\Attribute\CacheResponse;
use Componenta\Http\Cache\Policy\HttpCachePolicy;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use UnexpectedValueException;

final class CacheResponseCallbacksTest extends TestCase
{
    public function testCallbacksAreLazyAndResolveTheCurrentRequestInOrder(): void
    {
        $log = new class {
            /** @var list<array{string, ServerRequestInterface}> */
            public array $calls = [];
        };
        $attribute = new CacheResponse(
            ttl: 60,
            tags: [' catalog ', 'shared'],
            when: static function (ServerRequestInterface $request) use ($log): bool {
                $log->calls[] = ['when', $request];

                return true;
            },
            tagsFactory: static function (ServerRequestInterface $request) use ($log): array {
                $log->calls[] = ['tags', $request];

                return [' shared ', 'category:' . $request->getHeaderLine('X-Category'), 'catalog'];
            },
        );
        self::assertSame([], $log->calls);
        $first = new ServerRequest('GET', 'https://example.test/catalog', ['X-Category' => '1']);
        $second = $first->withHeader('X-Category', '2');

        $firstPolicy = $attribute->toPolicy($first);
        $secondPolicy = $attribute->toPolicy($second);

        self::assertTrue($firstPolicy->enabled);
        self::assertSame(['catalog', 'shared', 'category:1'], $firstPolicy->tags);
        self::assertSame(['catalog', 'shared', 'category:2'], $secondPolicy->tags);
        self::assertSame([['when', $first], ['tags', $first], ['when', $second], ['tags', $second]], $log->calls);
        self::assertEquals($secondPolicy, unserialize(serialize($secondPolicy)));
    }

    public function testVetoProducesDisabledPolicyAndSkipsTagsFactory(): void
    {
        $attribute = new CacheResponse(
            ttl: 60,
            tags: ['catalog'],
            when: static fn(ServerRequestInterface $request): bool => false,
            tagsFactory: static function (ServerRequestInterface $request): array {
                throw new RuntimeException('The veto must skip tag evaluation.');
            },
        );

        $policy = $attribute->toPolicy(new ServerRequest('GET', 'https://example.test/catalog'));

        self::assertFalse($policy->enabled);
        self::assertSame(60, $policy->ttl);
        self::assertSame(['catalog'], $policy->tags);
    }

    public function testPredicateCannotReplaceTheRequestSeenByTheTagsFactory(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/catalog', ['X-Category' => 'original']);
        $attribute = new CacheResponse(
            ttl: 60,
            when: static function (ServerRequestInterface &$current): bool {
                $current = $current->withHeader('X-Category', 'replacement');

                return true;
            },
            tagsFactory: static function (ServerRequestInterface $current) use ($request): array {
                self::assertSame($request, $current);

                return [$current->getHeaderLine('X-Category')];
            },
        );

        self::assertSame(['original'], $attribute->toPolicy($request)->tags);
    }

    public function testStaticDeclarationAndEmptyDynamicTagsPreserveStaticPolicy(): void
    {
        $static = new CacheResponse(ttl: 60, tags: ['catalog']);
        $dynamic = new CacheResponse(
            ttl: 60,
            tags: ['catalog'],
            tagsFactory: static fn(ServerRequestInterface $request): array => [],
        );

        self::assertEquals(
            $static->toPolicy(),
            $dynamic->toPolicy(new ServerRequest('GET', 'https://example.test/catalog')),
        );
    }

    #[DataProvider('callbackNames')]
    public function testCallbackConversionRequiresARequest(string $name): void
    {
        $attribute = $name === 'when'
            ? new CacheResponse(ttl: 60, when: static fn(ServerRequestInterface $request): bool => true)
            : new CacheResponse(ttl: 60, tagsFactory: static fn(ServerRequestInterface $request): array => []);

        $this->expectException(LogicException::class);

        $attribute->toPolicy();
    }

    /** @return iterable<string, array{string}> */
    public static function callbackNames(): iterable
    {
        yield 'predicate' => ['when'];
        yield 'tags' => ['tagsFactory'];
    }

    #[DataProvider('invalidPredicates')]
    public function testPredicateResultsAreNotCoerced(mixed $value): void
    {
        $attribute = new CacheResponse(
            ttl: 60,
            when: static fn(ServerRequestInterface $request): mixed => $value, // @phpstan-ignore argument.type
        );

        $this->expectException(UnexpectedValueException::class);

        $attribute->toPolicy(new ServerRequest('GET', 'https://example.test/catalog'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPredicates(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'string' => ['yes'];
        yield 'array' => [[]];
    }

    #[DataProvider('invalidTags')]
    public function testDynamicTagContractIsValidated(mixed $value): void
    {
        $attribute = new CacheResponse(
            ttl: 60,
            tagsFactory: static fn(ServerRequestInterface $request): mixed => $value, // @phpstan-ignore argument.type
        );

        $this->expectException(UnexpectedValueException::class);

        $attribute->toPolicy(new ServerRequest('GET', 'https://example.test/catalog'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTags(): iterable
    {
        yield 'null' => [null];
        yield 'scalar' => ['catalog'];
        yield 'associative' => [['tag' => 'catalog']];
        yield 'sparse' => [[1 => 'catalog']];
        yield 'non-string' => [[1]];
        yield 'empty tag' => [['']];
        yield 'blank tag' => [[" \t\n"]];
    }

    #[DataProvider('callbackNames')]
    public function testCallbackExceptionsPropagateUnchanged(string $name): void
    {
        $failure = new RuntimeException('Application rule failed.');
        $callback = static function (ServerRequestInterface $request) use ($failure): never {
            throw $failure;
        };
        $attribute = $name === 'when'
            ? new CacheResponse(ttl: 60, when: $callback)
            : new CacheResponse(ttl: 60, tagsFactory: $callback);

        $this->expectExceptionObject($failure);

        $attribute->toPolicy(new ServerRequest('GET', 'https://example.test/catalog'));
    }

    public function testExplicitDisabledConfigurationIsStrictAndRetainsStaticValidation(): void
    {
        self::assertFalse(HttpCachePolicy::fromArray(['ttl' => 60, 'enabled' => false])->enabled);
        self::assertTrue(HttpCachePolicy::fromArray(['ttl' => 60])->enabled);
        $this->expectException(\InvalidArgumentException::class);

        HttpCachePolicy::fromArray(['ttl' => 60, 'enabled' => 'false']);
    }

    public function testDisabledPolicyStillRejectsUnsafeStaticSettings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpCachePolicy(ttl: 60, allowAuthenticated: true, enabled: false);
    }
}
