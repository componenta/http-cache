# Componenta HTTP Cache

Route-aware PSR-15 HTTP response caching for Componenta.

The cache is conservative by design: it caches only explicitly configured GET/HEAD routes, rejects unsafe response metadata, and falls back to the origin handler when the cache backend cannot be used.

## Requirements

- PHP 8.5.13+
- Componenta Config 4
- Componenta Router 3.1+
- Componenta HTTP 1
- PSR-7 / PSR-17 factories
- PSR-16 cache implementation
- Optional application-provided PSR-20 `ClockInterface` (UTC system clock by default)

For the development branch and its unreleased dependency revisions, see [development integration](docs/development.md).

## Middleware order

Route matching must happen before the response cache so the cache can resolve the route policy. The cache must wrap route dispatch so it can observe the generated response. Authorization, session/token revocation checks, access-control middleware, security-sensitive rate limits, and any other guards that must run on every request must execute **before** the cache. Do not put them only inside route dispatch: a hit deliberately skips dispatch. If a route cannot meet this requirement, disable caching for it.

A typical order is:

1. trusted proxy normalization, when applicable
2. route matching
3. resolve trusted tenant/locale context, authenticate and authorize the current request
4. response cache
5. route dispatch

When the application is behind a reverse proxy, normalize the PSR-7 request URI with the trusted-proxy middleware before this cache. Do not build the effective URI directly from untrusted forwarded headers. Scheme, effective authority, URI path, raw URI query, Host field and wire request target participate independently in the cache identity. Do not derive representation selection from unkeyed request inputs. Set a separate key prefix for independent applications or deployments sharing a backend.

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

Only the exact case-sensitive GET and HEAD methods can be served from this cache. Other unsafe methods reach the origin handler unless `only-if-cached` is present, in which case the cache returns 504 without performing a mutation. Successful 2xx/3xx unsafe responses invalidate every cached representation of the effective target URI.

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

Callbacks run again on each policy resolution, including cache hits, without invoking the origin on a hit. Unsafe requests do not evaluate callbacks; `only-if-cached` prevents origin execution even for unsafe methods. A `true` predicate continues through all existing cache safety checks. Resolved policies contain data only; requests, closures, and callback results are not persisted or memoized by route.

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

Private entries are application-managed credential-partitioned memoization, not a generic shared RFC HTTP cache. Their keys include both presented `Authorization` and `Cookie` field values. An enabled private policy enforces an unqualified `Cache-Control: private`, removes `public`, `s-maxage` and shared-proxy/CDN cache overrides, preserves restrictive directives, and varies on both credential fields on hits, misses and bypasses. An explicit callback veto leaves the origin response unchanged.

A private policy without credential partitioning is rejected. A request using a private policy but carrying no credentials bypasses the cache.

A credential string is not proof of current authorization. Authenticate and authorize each request before cache use, including token/session expiry and permission checks. Routes whose representation depends on server-side user state, privilege or tenant selection must partition or invalidate on that state; for sensitive content that must never be retained, use `no-store` or disable caching. Responses containing `Set-Cookie` are never stored.

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

Range responses and offload responses (`X-Accel-Redirect`, `X-Sendfile`) are not cached. GET bodies with inconsistent Content-Length or incomplete stream reads are rejected. A conditional hit producing 304 does not reconstruct the stored body. The freshness budget starts at origin response receipt and is reduced by preparation and storage work; a registered PSR-20 clock is shared by the default factories.

## Storage safety

PSR-16 keys are portable SHA-256 hexadecimal keys. The configured key prefix, full request identity, route, policy fingerprint, declared variants and tag generations participate in the key. HTTP Cache 2 changes the key namespace intentionally for the URI and generation security fixes: existing 1.x entries are not reused.

The default maximum cached entry size is 8 MiB:

```php
ConfigKey::MAX_ENTRY_BYTES => 8_388_608,
```

The limit applies to stored headers and body. Only complete readable, seekable streams are snapshotted; their original positions are restored even on read failure when the stream supports restoration. Connection-specific fields are removed, but responses that nominate cache or representation metadata for removal are rejected. Stored payloads are checked for valid statuses, timestamps, header structure, control characters, forbidden fields, directives and size. Custom stores must preserve the same admission and freshness contracts.

## Invalidation

Application tags can be invalidated through `CacheInvalidatorInterface`.

Target URI generations are also used internally. After a successful unsafe request the target URI generation is changed, making prior GET/HEAD entries unreachable.

Tag generations use portable hashed PSR-16 keys. Reads are batched; missing, evicted and corrupt generations are replaced with a freshly persisted positive random value, never a fixed initial generation. This avoids resurrecting entries after metadata eviction. In-flight reads that already obtained an old generation can complete, but their writes cannot be reached through the new generation. PSR-16 does not provide atomic multi-key invalidation, distributed locks or transactional consistency. If invalidation fails, log/monitor the failure and apply application-level retry or recovery; the middleware does not undo an already committed mutation.

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

Run `composer bench` after installing the development dependency graph. PHPBench measures public middleware hits, callback-policy hits, conditional 304 hits, cold misses (including eviction of the previous entry), request bypass, a 1 MiB response miss, batched tag generation reads and tag invalidation. The benchmark backend is in-memory PSR-16: these are microbenchmarks, not distributed cache or HTTP server load tests. CI runs the suite on the latest supported PHP 8.5 patch with highest dependencies; no hardware-dependent latency threshold is imposed.
