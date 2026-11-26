# Development integration

HTTP Cache 2.x requires PHP 8.5.13+, Componenta Config 4, HTTP 1 and Router 3.1 or later within 3.x. The minimum PHP version preserves the native trait callable scope/visibility fix in PHP 8.5.13. These are breaking baseline changes relative to HTTP Cache 1.x.

## Current integration graph

CI installs published Config 4, Reflection 3, HTTP 1, HTTP Responder 2, MIME Type Detector 2 and Pipeline 1.1.1+ within 1.x. It uses the actual unreleased sources below for packages that have not yet published a compatible release. No package is replaced, provided under a false version, or assigned a synthetic version alias.

| Package | Source branch | Declared version line | Revision |
|---|---|---|---|
| `componenta/di` | `main` | `6.0.x-dev` | [`e203077d1204`](https://github.com/componenta/di/commit/e203077d12048936f078d37d1cf3badd661e1716) |
| `componenta/router` | `fix/http-cache-dependency-integration-2026-11-26` | `3.1.x-dev` | [`afe53a6b2d58`](https://github.com/componenta/router/commit/afe53a6b2d58cc235ef177eef6318e93d876350b) |
| `componenta/middleware-factory` | `refactor/middleware-factory-audit` | `3.0.x-dev` | [`f40f9938e8d6`](https://github.com/componenta/middleware-factory/commit/f40f9938e8d6a2e24a097c496a547776e3ebf0fd) |

The Router integration branch adds support for Responder 2 and Middleware Factory 3 without changing its routing API. Its compatibility range is additive; HTTP Cache CI selects the new major lines, not the earlier interim compatibility branches. Publication requires compatible stable Router, Middleware Factory and DI releases. Pipeline 1.1.1 is already released.

The machine-readable inventory is [development-dependencies.json](../.github/development-dependencies.json). The preparation script validates source revisions, names and declared aliases and creates a separate Composer root. It also requires published Responder and MIME Type Detector 2 and Pipeline 1.1.1+ for development acceptance. The tracked package manifest remains unchanged.

## Reproduce the integration

From a fresh package checkout with PHP 8.5.13+ and Composer 2:

```bash
mkdir -p .development-dependencies
git clone --branch main https://github.com/componenta/di.git .development-dependencies/di
git -C .development-dependencies/di switch -C main e203077d12048936f078d37d1cf3badd661e1716
git clone --branch fix/http-cache-dependency-integration-2026-11-26 https://github.com/componenta/router.git .development-dependencies/router
git -C .development-dependencies/router switch -C fix/http-cache-dependency-integration-2026-11-26 afe53a6b2d58cc235ef177eef6318e93d876350b
git clone --branch refactor/middleware-factory-audit https://github.com/componenta/middleware-factory.git .development-dependencies/middleware-factory
git -C .development-dependencies/middleware-factory switch -C refactor/middleware-factory-audit f40f9938e8d6a2e24a097c496a547776e3ebf0fd
php .github/scripts/prepare-development.php
composer validate --strict
COMPOSER=.composer-development.json composer update --no-interaction --prefer-dist
COMPOSER=.composer-development.json composer validate --strict
composer check
COMPOSER=.composer-development.json composer audit
```

The quality workflow tests PHP 8.5.13 and the current 8.5 patch against both lowest and highest compatible dependencies. The release workflow installs the tracked public manifest without any source overrides and is intentionally blocked until the required compatible stable dependency graph exists.

The callback specification and its native tests remain mandatory. PHP 8.5 introduced constant-expression callback syntax; [PHP 8.5.13](https://www.php.net/ChangeLog-8.php#8.5.13) fixes the trait callable scope and visibility behavior. Native closure persistence is not part of this feature.
