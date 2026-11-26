# Request-dependent callbacks for CacheResponse attributes

**Readiness:** blocked by B1, review of the referenced conversation and its material decisions. This is a repository-grounded design draft, not an executable implementation instruction. The proposed target below must be reconciled with that conversation before production slices are derived.

## Objective

Allow a route's `CacheResponse` attribute to make a request-dependent caching decision and add request-derived invalidation tags. Resolve these callbacks into ordinary per-request policy data before cache lookup while retaining the existing policy precedence, cache identity, authentication partitioning, response safety checks, and HTTP conditional-response behavior.

## Scope

Add two optional attribute callbacks: `when` and `tagsFactory`. A request predicate can veto cache use; a tags factory adds application tags derived from the request. Both operate on the current `ServerRequestInterface` and are evaluated before generating the lookup key.

The change does not add arbitrary cache-key replacement, response-dependent callbacks, dynamic TTL/status/Vary providers, DI-aware callback invocation, authorization, persistent callback serialization, or a second cache store. Existing `CacheKeyGeneratorInterface` remains the explicit customization boundary for an application's complete key strategy. The callback feature does not replace that contract.

## Governing evidence

The inspected baseline is `componenta/http-cache` `main` at `fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3`; no other branches were discovered. No `AGENTS.md` or existing package specification directory was present in the inspected tree.

Relevant current sources:

- [CacheResponse](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Attribute/CacheResponse.php) validates static settings through `HttpCachePolicy` and exposes `toPolicy(): HttpCachePolicy`.
- [HttpCachePolicy](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Policy/HttpCachePolicy.php) owns policy normalization and safety invariants. It is currently a data-only readonly value.
- [AttributeCachePolicyProvider](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Policy/AttributeCachePolicyProvider.php) resolves handler/class attributes for the current route. A method attribute overrides a class attribute.
- [CompositeCachePolicyProvider](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Policy/CompositeCachePolicyProvider.php) chooses the first non-null policy. Its [factory](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Factory/CompositeCachePolicyProviderFactory.php) puts configured route policies before attributes.
- [DefaultCacheKeyGenerator](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Key/DefaultCacheKeyGenerator.php) hashes the configured prefix, method, target identity, route, policy fingerprint, declared request-header variants, and tag generations. It also adds the target-URI tag.
- [ResponseCacheMiddleware](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/src/Middleware/ResponseCacheMiddleware.php) owns cache lookup, hit validation, origin invocation, response eligibility, storage, HTTP conditionals, and unsafe-method target invalidation. Policy resolution is outside its backend-failure fallback blocks.
- [README](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/README.md) documents conservative response caching, configured-policy precedence, credentials, Vary, cache directives, and failure behavior.
- [composer.json](https://github.com/componenta/http-cache/blob/fbf72d98dca3172eb5a605fd8e38a5e99b4d54c3/composer.json) requires PHP `^8.4` and no Componenta DI package. PHPUnit 12 and PHPStan are already used; established commands are `composer test`, `composer phpstan`, and `composer check`.

PHP 8.5 permits static anonymous functions and first-class callables in attribute arguments. Anonymous functions in these constant expressions cannot capture external variables, use `$this`, or use arrow-function syntax. See the [closure constant-expression RFC](https://wiki.php.net/rfc/closures_in_const_expr) and [first-class callable manual](https://www.php.net/manual/en/functions.first_class_callable_syntax.php).

Syntax availability is distinct from the supported native first-class callable attribute contract. That contract requires PHP 8.5.13+ because earlier PHP 8.5 releases could resolve a static callable in a trait constant expression against the first using class and bypass visibility checks. The [PHP 8.5.13 changelog](https://www.php.net/ChangeLog-8.php#8.5.13) records the native fix. The package must not emulate or patch this engine behavior.

Source inspection establishes the baseline. No production implementation or test execution was performed for this specification-only work.

## Requirements and invariants

- **R1 — Request veto:** `when` is evaluated against the current request before any cache lookup for the selected attribute policy. `false` prevents both cache reuse and cache storage for that request. It does not mean that no policy was declared.
- **R2 — Dynamic tags:** `tagsFactory` produces additional request-derived tags before the lookup key and tag generations are calculated. Static tags remain active.
- **R3 — Current request:** request-derived values are recomputed for every policy resolution; they are not cached by handler, route, callback identity, or prior result.
- **R4 — Explicit errors:** invalid callback results and callback evaluation errors fail policy resolution visibly. They are not silently interpreted as permission to cache, absence of a declaration, or an ordinary backend outage.
- **I1 — Safety only tightens:** a `true` predicate permits continuing normal evaluation. It cannot override method restrictions, credentials, request directives/preconditions, response status/directives, `Set-Cookie`, `Content-Range`, Vary validation, or entry-size limits.
- **I2 — Full identity:** callbacks cannot remove target, method, route, policy, Vary, credentials, configured prefix, or tag-generation dimensions from the default cache key.
- **I3 — Hit semantics:** a cache hit does not execute the origin merely to obtain callback inputs. Callbacks do not receive the handler result or response.
- **I4 — No callback persistence:** only resolved scalar/list policy data reaches key generation and the response store. No closure, request object, callback result cache, or callback-created service is persisted by the cache feature.

### Preservation constraints

- **P1 — Existing static declarations:** no-callback attributes retain current normalization, key identity, request/response behavior, and the ability to call `toPolicy()` without a request.
- **P2 — Policy precedence:** configured route policy remains the default first choice; method attributes continue overriding class attributes; composites continue selecting the first non-null declaration. A selected declaration's veto cannot fall through to a later provider or class attribute.
- **P3 — Protocol behavior:** `only-if-cached` never calls the origin; when no usable entry can be served it returns 504. Existing conditional 304 handling, age/freshness rules, origin-only preconditions, HEAD behavior, and unsafe-request invalidation remain unchanged.
- **P4 — Failures at existing boundaries:** key/store/read/write/reconstruction/invalidation failures keep their existing fallback/logging semantics. Callback policy errors are not moved into those backend-error catch blocks.

## Desired-state architecture

### Ownership

The attribute owns static declaration settings and callback references. The attribute provider owns selecting the declaration and resolving it with the request. `HttpCachePolicy` remains data-only and owns normalized, request-resolved policy data. The middleware owns all existing cache safety and protocol decisions. The key generator owns the complete cache identity and tag-generation lookup.

Callback evaluation uses direct invocation with a fixed signature. No DI/container dependency is introduced. Route matching must still precede cache middleware, and dispatch remains inside it. Applications must put any middleware responsible for trusted tenant/locale context before caching when callbacks or representations depend on that context; callbacks do not authenticate those values.

### Explicit disabled policy

Append an `enabled: bool = true` property/constructor argument to `HttpCachePolicy`. `false` represents a selected declaration that forbids cache use for this request. It is distinct from `null`, which still means that a provider found no declaration.

This distinction is required by existing composition: returning `null` after a predicate veto could activate a later provider and cache the response despite the veto. Introducing a separate resolution hierarchy or storing closures in resolved policies would add a larger boundary than the current problem requires.

The resolved policy retains all existing static settings and validation even when disabled. Disabling does not make an otherwise invalid configuration valid. The middleware checks `enabled` before key generation/store lookup and follows the current non-cacheable-policy path when it is false. This yields the origin response normally, or 504 without origin invocation under `only-if-cached`.

`HttpCachePolicy::fromArray()` accepts optional boolean `enabled`, defaulting to `true`, using the existing strict boolean-value handling. This allows the explicit selected-disabled state to survive the existing public configuration construction path. No separate callback keys are added to configuration arrays.

The key generator must not append the new always-true `enabled` value to existing enabled-policy fingerprints merely for completeness: static no-callback entries must retain their current key namespace. Disabled policies do not enter middleware key generation or store access.

## Callback contracts

### Attribute constructor

Preserve the existing parameter order and append:

```php
?\Closure $when = null,
?\Closure $tagsFactory = null,
```

The existing `tags` parameter remains an array of static tags; it does not become a union that ambiguously switches between static data and executable behavior. The two callback references are stored without execution. Static settings continue to be validated at construction using existing policy validation.

The callback signatures are:

```text
when: Closure(ServerRequestInterface): bool
tagsFactory: Closure(ServerRequestInterface): list<string>
```

These signatures describe runtime results, not PHP-enforceable generic function types. The package validates the returned value at the boundary. It does not inspect callback parameter names or resolve extra arguments. No response, current container, handler, service bag, or previous policy result is an implicit input.

### Policy conversion and provider integration

Extend the public conversion method to:

```php
public function toPolicy(
    ?\Psr\Http\Message\ServerRequestInterface $request = null,
): \Componenta\Http\Cache\Policy\HttpCachePolicy;
```

For a declaration with no callbacks, omitted request preserves existing conversion. For a declaration containing either callback, omitting the request raises `LogicException`; the method must not silently produce a static policy that skips the callbacks.

`AttributeCachePolicyProvider::policyFor($request)` selects the existing highest-precedence attribute and passes that same request to conversion. Callback resolution does not occur in the constructor or during a metadata scan. Selection is completed before resolution: a method predicate returning false returns that method's disabled policy and does not cause class-level fallback.

The default composite still checks config policies before attributes. If config supplies a policy, the attribute callback is not consulted. This is existing explicit override behavior, not a bypass introduced by the callback feature. If a custom composite puts attributes first, a selected disabled policy is still non-null and therefore stops fallback.

### Evaluation order

For one selected declaration:

1. If `when` exists, require a request and invoke it once with that request.
2. Require its result to be a native boolean. No truthiness coercion is permitted at this boundary.
3. If false, return a data-only disabled policy containing the normalized static settings. Do not invoke `tagsFactory`.
4. Otherwise, if `tagsFactory` exists, invoke it once with the same request.
5. Validate its return as a list of strings, with no empty or whitespace-only tag. An empty list is valid and contributes nothing.
6. Combine static tags followed by dynamic tags and pass them through the same trimming/deduplication semantics as current `HttpCachePolicy` tag validation. Preserve first occurrence order.
7. Return an enabled, normalized, data-only `HttpCachePolicy`.

The middleware evaluates a selected policy before key lookup on safe requests. Unsafe requests retain their existing early path and do not invoke attribute callbacks. The provider's public `policyFor()` method independently resolves the declaration for the request supplied by its caller; it does not duplicate the middleware's request eligibility rules.

Dynamic tags are application invalidation groups. Their ordered output must be deterministic for equivalent request inputs, since the policy fingerprint and tag-generation data contribute to the key. A callback may return different values for a later request; the package must reevaluate it. This is not permission to derive tenant or authorization identity from untrusted input without the application's existing validation.

### Error behavior

- `when` returning anything other than `bool` raises `UnexpectedValueException`.
- `tagsFactory` returning a non-array, non-list, non-string member, or empty/blank tag raises `UnexpectedValueException` identifying the callback contract violation. Do not render request credentials or raw callback values in the error message.
- A callback-thrown `Throwable` propagates unchanged out of policy resolution.
- Native attribute construction and invalid static settings preserve their existing errors.
- No error triggers policy fallback, a default `true`, static-tags-only caching, or origin invocation from inside the provider.

This is consistent with the current boundary: invalid policy construction and `policyFor()` failures are not treated as unavailable cache storage. The outer application's normal exception handling remains responsible for turning an application/configuration error into an HTTP response.

## Cache identity, invalidation, and lifecycle

`DefaultCacheKeyGenerator` continues adding the target-URI tag and obtaining the current generation of every resolved application tag. Dynamic tags therefore participate before both reads and writes. Invalidating one of them changes the key produced on the next equivalent request, making the previous entry unreachable under the existing generation scheme.

The new feature does not change URI identity or normalization, credential values included in private-cache keys, declared `varyHeaders`, response `Vary` checks, or the response payload format. The callback cannot manufacture a complete key and omit these dimensions.

Cache predicates and tags are evaluated from request information available before the origin executes. A request-local decision must therefore be available on a cache hit as well as a miss. Fetching a handler result solely to determine tags or predicate outcome is outside this API.

Attributes and conversion results are not memoized by route. A long-lived provider or middleware must not retain a prior request, its tags, or its enabled state. The package does not deep-clone requests or redefine PHP closure lifetime/state. Runtime callbacks remain developer-supplied code; they should perform the small request calculation represented by this contract and avoid request-external side effects.

## Proposed usage

These examples show the proposed API after implementation, not current package syntax.

```php
use Componenta\Http\Cache\Attribute\CacheResponse;
use Psr\Http\Message\ServerRequestInterface;

#[CacheResponse(
    ttl: 60,
    varyHeaders: ['Accept-Language'],
    tags: ['catalog'],
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
final class CatalogHandler
{
    public function __invoke(): mixed
    {
        // Application handler body.
    }
}
```

The application must resolve `previewMode` and `categoryId` before cache middleware. The example's callbacks do not replace the existing URI/route/Vary dimensions. A reusable callback can instead be declared with `tagsFactory: CacheTags::forCatalog(...)`; lexical visibility and native first-class callable semantics must be preserved.

## Compatibility and runtime

Preserve current constructor parameter positions; append callback parameters and the new resolved-policy flag. Existing static attributes and configured policies continue to produce enabled policies by default. Preserve `toPolicy()` for declarations without callbacks and keep old enabled-policy key fingerprints stable.

The package currently advertises PHP `^8.4`. Native callback syntax became available in PHP 8.5, while the supported native first-class callable attribute contract in this draft requires PHP 8.5.13+. B1 must settle the package-wide minimum: raise it, or retain PHP 8.4 static/runtime-construction support with separately gated PHP 8.5.13+ native-attribute coverage. Composer, CI, docs, and fixture loading must distinguish those supported surfaces consistently; PHP 8.5.0–8.5.12 must not be advertised as satisfying the full native callable scope/visibility contract. Do not add DI 6 solely to invoke these fixed-signature callbacks.

Existing response-cache payloads remain readable. Dynamic tags naturally select keys reflecting the newly configured policy; no response-store serialization migration or closure serialization support is introduced.

## Verification strategy

Use TDD against public attribute conversion, provider composition, key generation, and PSR-15 middleware behavior. Establish the missing public capability before implementation; make the minimal change; prove the desired behavior and a preservation control. Do not test private helper names or exact hashing internals when a separation/equality observation proves the contract.

| Requirement / invariant | Public verification |
|---|---|
| R1, P2 | A false method predicate produces a non-null disabled policy, does not fall back to its class declaration, and stops a later provider in a custom composite. Config-first default override remains unchanged. |
| R1, I1 | Seed a usable entry, then request the same resource with a false predicate: the cache does not return it or store the origin response. With `only-if-cached`, return 504 and do not invoke origin. |
| R2 | Static and dynamic tags are combined, trimmed, deduplicated, and ordered as specified. Empty dynamic list preserves static tags; malformed callback results fail visibly. |
| R2, I2 | For equivalent requests, change the generation of a dynamic tag and observe a different generated key/cache miss; target-URI invalidation remains effective. |
| R3, I4 | Reuse one provider and middleware across requests with distinct category/preview values; policy tags and veto outcome always reflect the current request, with no cross-request retention. |
| R4, P4 | A thrown callback error propagates and triggers neither later-provider fallback nor store/origin access from the failed cache path. Existing store/key failure fallback tests still pass. |
| I1, P3 | A predicate returning true cannot make authenticated public requests cacheable, permit private caching without credentials, store Set-Cookie, accept undeclared or wildcard Vary, or cache request no-store/range/precondition paths. |
| I2, P1 | Default enabled-policy keys for old static declarations are unchanged; different credentials, target origins, raw queries, configured namespaces, or declared Vary fields still partition entries with callbacks present. |
| I3 | A hit evaluates only request callbacks, returns the cached response, and does not call origin. On a normal miss, origin runs once under the existing middleware contract. |
| P3 | Unsafe-method success invalidates the target without invoking callbacks; existing age/freshness, 304 metadata, HEAD, and only-if-cached tests remain preservation controls. |
| Construction/syntax | Constructing/reflecting callback metadata does not execute its rule. Static anonymous functions and private static first-class callables work on supported PHP 8.5.13+ runtimes; callback-backed `toPolicy()` without a request fails. |
| Native scope | A shared trait declaring a first-class callable in a constant expression is used by two handler classes with their own private static predicate/tag methods. Repeatedly resolve both handlers' attributes on PHP 8.5.13+ and prove the correct class-specific decision/tags and scope are retained; native invalid private access must remain rejected. |

Relevant existing proof locations are `tests/CacheResponseAttributeTest.php`, `tests/AttributeCachePolicyProviderTest.php`, `tests/CompositeCachePolicyProviderTest.php`, `tests/HttpCachePolicyTest.php`, `tests/DefaultCacheKeyGeneratorTest.php`, and `tests/ResponseCacheMiddlewareTest.php`. Use existing request/store test doubles and PSR-7 fixtures. Do not replace PHPUnit or migrate unrelated tests as part of this feature.

After focused behavior checks, run the established `composer check` gate on the agreed minimum runtime and supported dependency combinations. Recheck the documented static-key preservation case and direct callback signature with static analysis. Do not claim performance improvements without measurements; the design adds request computation and keeps it out of origin execution and persisted metadata.

## Technical change surface

- `src/Attribute/CacheResponse.php`: append and retain callbacks; support request-aware conversion and static compatibility; resolve and validate callback results into data-only policy values.
- `src/Policy/AttributeCachePolicyProvider.php`: carry the current request through selected-declaration conversion without changing lookup or fallback precedence.
- `src/Policy/HttpCachePolicy.php`: append explicit `enabled` data, default true, including strict array-config construction; retain all existing validation.
- `src/Middleware/ResponseCacheMiddleware.php`: honor the resolved selected-disabled policy before key/store access; retain existing safety/protocol paths.
- Existing test files listed above: prove callback behavior, veto/fallback distinction, cross-request independence, security gates, and static-key preservation.
- `README.md`: describe callback signatures, evaluation timing, disabled-vs-absent distinction, middleware prerequisites, runtime syntax, and failure behavior.
- Composer/CI runtime configuration: change only according to B1's settled runtime decision.

`DefaultCacheKeyGenerator` is a verification boundary, not an automatic rewrite target: dynamic tags already flow through its policy fingerprint and generation lookup. `CachePolicyProviderInterface`, `CacheKeyGeneratorInterface`, response-store interfaces/serialization, and composite-provider ordering are retained. No new provider/resolver interface or persisted callback representation is required.

## Blocking evidence and implementation sequencing

### B1 — Referenced conversation and material callback decisions

The required linked design discussion was unavailable during this specification-only review. It may settle callback input/DI semantics, key customization, the enabled/veto result model, or runtime compatibility differently. Its decisions must be reconciled with this document before implementation readiness can be claimed.

The bounded evidence task is to retrieve the relevant text/decisions and reconcile the proposed fixed request callback contract, additive tags, explicit selected-disabled state, and runtime policy. No production mutation or prototype is needed. Stop when a single target is agreed by available task evidence and no material callback/policy behavior remains conditional.

Do not execute production slices while B1 remains. After reconciliation, derive slices in this dependency order: explicit selected-disabled policy and predicate behavior through provider/middleware; dynamic tags through key/invalidation and request-lifecycle proof; native syntax, safety/protocol preservation, docs/runtime consistency, and convergence. Each slice must refer to the settled target and establish its public failing signal before implementation.

## Convergence check

The implementation is complete only when R1–R4, I1–I4, and P1–P4 hold together. Reject an implementation that treats false as absence, retains callbacks in resolved policy/storage, caches dynamic results by route, weakens Vary/credential partitioning, catches policy errors as backend outages, evaluates response-dependent logic on a hit, or changes static key identity without an explicit revised decision. Confirm examples use supported PHP 8.5 attribute expressions and the published runtime policy matches Composer/CI.
