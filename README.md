# Componenta HTTP Cache

Route-aware PSR-15 HTTP response caching for Componenta.

The cache is conservative by design: it caches only explicitly configured GET/HEAD routes, rejects unsafe response metadata, and falls back to the origin handler when the cache backend cannot be used.

## Requirements

- PHP 8.5.13+
- Componenta Config 4
- Componenta Router 3
- Componenta HTTP 1
- PSR-7 / PSR-17 factories
- PSR-16 cache implementation

For the development branch and its unreleased dependency revisions, see [development integration](docs/development.md).

## Middleware order

Route matching must happen before the response cache so the cache can resolve the route policy. The cache must wrap route dispatch so it can observe the generated response.

A typical order is:

1. trusted proxy normalization, when applicable
2. route matching
3. response cache
4. route dispatch

When the application is behind a reverse proxy, normalize the PSR-7 request URI with the trusted-proxy middleware before this cache. Do not build the effective URI directly from untrusted forwarded headers. Scheme, authority, path, raw query and request target participate in the cache identity.

## Policies

Policies can be configured by route name or declared with `#[CacheResponse]`.

```php
use Componenta\Http\Cache\Attribute\CacheResponse;

#[CacheResponse(
    ttl: 60,
    varyHeaders: ['Accept-Language'],
    tags: ['catalog'],
)]
final class CatalogHandler
{
    public function __invoke(): mixed
    {
        // ...
    }
}
```

Configured route policies take precedence over attributes.

Only GET and HEAD can be served from this response cache. Unsafe methods always reach the origin handler. Successful 2xx/3xx unsafe responses invalidate the cached target URI generation.

### Request callbacks

Append `when` to decide whether this request may use the cache and `tagsFactory` to add request-derived invalidation groups:

```php
use Psr\Http\Message\ServerRequestInterface;

#[CacheResponse(
    ttl: 60,
    tags: ['catalog'],
    varyHeaders: ['Accept-Language'],
    when: static function (ServerRequestInterface $request): bool {
        return $request->getAttribute('previewMode', false) === false;
    },
    tagsFactory: static function (ServerRequestInterface $request): array {
        $categoryId = $request->getAttribute('categoryId');
        if (!is_int($categoryId) || $categoryId < 1) {
            throw new \UnexpectedValueException('A resolved category ID is required.');
        }

        return ['category:' . $categoryId];
    },
)]
final class PreviewAwareCatalogHandler
{
    public function __invoke(): mixed
    {
        // Application handler body.
    }
}
```

Resolve trusted application context such as `categoryId` before the cache middleware. Each callback receives only the current `ServerRequestInterface`; the container, handler result, and response are not implicit inputs. The constructor stores callbacks without invoking them. A reusable callback can use `tagsFactory: CacheTags::forCatalog(...)`, including a private static method within its declaring class. Native attribute arguments support `static function` and first-class callables, with no arrow functions or captured variables. The PHP 8.5.13 minimum includes the native trait callable scope/visibility fix.

`when` runs once before key generation and must return a boolean. `false` produces a selected `HttpCachePolicy` with `enabled: false`, bypassing both cache reads and writes and skipping `tagsFactory`. The origin handles the request normally; `only-if-cached` returns 504 without invoking it. A veto cannot fall through to a class attribute or later provider. Configured route policies still take precedence over attributes; an explicit configured policy does not consult the attribute callbacks. Array configuration also accepts a strict boolean `enabled` flag.

`tagsFactory` must return a list of nonblank strings. Static tags come first; all tags are trimmed and deduplicated in first occurrence order. The resolved tags participate in the existing policy fingerprint and generation lookup before both reads and writes. Equivalent request inputs should produce deterministic tag order. Existing target, method, route, Vary, credentials, and namespace partitions remain active.

Callbacks run again on each policy resolution, including cache hits, without invoking the origin on a hit. Unsafe requests keep their origin/invalidation path without evaluating callbacks. A `true` predicate continues through all existing cache safety checks. Resolved policies contain data only; requests, closures, and callback results are not persisted or memoized by route.

Direct `toPolicy()` remains valid for a static attribute. A callback-backed attribute requires `toPolicy($request)`; omitting the request raises `LogicException`. Invalid callback results raise `UnexpectedValueException`. Callback-thrown errors propagate unchanged from policy resolution and never enter the cache-backend fallback path.

## Authentication and private responses

Public cache policies are bypassed when the request contains `Authorization` or `Cookie`.

Credential-partitioned private caching must be enabled explicitly:

```php
new HttpCachePolicy(
    ttl: 60,
    private: true,
    allowAuthenticated: true,
);
```

Private entries are keyed by the presented `Authorization` and `Cookie` field values in addition to the normal cache identity, and the outgoing response is forced to `Cache-Control: private`.

A private policy without credential partitioning is rejected. A request using a private policy but carrying no credentials bypasses the cache.

Responses containing `Set-Cookie` are never stored.

## Vary

All request fields that can select a representation must be declared in `varyHeaders`.

If the generated response contains a `Vary` member that was not declared by the policy, the response is not stored. `Vary: *` is never stored.

This prevents a response selected by one request header set from being reused for another variant.

## Cache-Control and validators

The middleware preserves application-provided `Cache-Control` directives and honors restrictive directives such as `no-store` and `no-cache`.

It supports:

- `max-age`, `s-maxage`, `Expires`
- request `max-age`, `min-fresh`, `no-cache`, `no-store`, `only-if-cached`
- weak `If-None-Match` comparison
- `If-Modified-Since`
- RFC age calculation using `Date`, `Age`, request time and response time
- generated weak SHA-256 ETags for bounded seekable GET bodies

Origin-only preconditions such as `If-Match` and `If-Unmodified-Since` bypass cache reuse and are forwarded to the origin handler.

Range responses are not cached.

## Storage safety

PSR-16 keys are portable SHA-256 hexadecimal keys. The configured key prefix, target URI, route, policy fingerprint, declared variants and tag generations participate in the key.

The default maximum cached entry size is 8 MiB:

```php
ConfigKey::MAX_ENTRY_BYTES => 8_388_608,
```

The limit applies to stored headers and body. Connection-specific fields are removed before storage. Cached payloads are validated again on read, including header names, CR/LF rejection and size limits.

## Invalidation

Application tags can be invalidated through `CacheInvalidatorInterface`.

Target URI generations are also used internally. After a successful unsafe request the target URI generation is changed, making prior GET/HEAD entries unreachable.

Tag generations use portable hashed PSR-16 keys and self-heal corrupted backend values. Failed invalidation is treated as an error condition and is reported through the optional logger.

## Failure behavior

If a `Psr\Log\LoggerInterface` service is registered, cache key, read, reconstruction, write and invalidation failures are logged without including request credentials in the log message.

Cache lookup and write failures fail open to the origin response. `only-if-cached` never reaches the origin and returns 504 when a usable stored response cannot be produced.

The optional debug response header can be enabled with:

```php
ConfigKey::DEBUG_HEADER => true,
```

It reports cache states such as `HIT`, `MISS`, `BYPASS` and `INVALIDATION-FAILED`.

## Verification

The repository quality workflow tests PHP 8.5.13 and the current PHP 8.5 patch against both lowest and highest compatible dependency sets, using the actual development dependencies described in [development integration](docs/development.md). It runs strict Composer validation, dependency security audit, PHPStan at level max for `src` and `tests`, and PHPUnit tests.
