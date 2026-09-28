# Changelog

All notable changes to `sailantis/azera-framework` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-09-28

The first release prepared for public installation from Packagist. It wires up
the `azera` CLI binary, pins the template engine to a stable release and adds
the packaging and community files a published library is expected to carry.

### Added

- **`bin/azera`** is now declared in `composer.json`, so Composer links it as
  `vendor/bin/azera` (with an `azera.bat` shim on Windows) for consuming
  projects. The documented CLI workflow works after `composer require`.
- Continuous integration (`.github/workflows/tests.yml`): the suite runs on
  PHP 8.2, 8.3 and 8.4, together with `composer validate --strict`.
- `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `.editorconfig` and
  `.gitattributes` (the latter trims development files from the package
  archive).
- Package metadata for Packagist: `keywords`, `homepage`, `support` and
  `authors`, plus `config.sort-packages`.

### Changed

- `sailantis/clarity-engine` is required at `^0.1` and resolved from Packagist
  instead of the local `dev-main` path repository. The path repository entry
  was removed: a `path` URL pointing at an absent directory makes
  `composer install` fail outright.
- PSR dependency constraints relaxed to caret ranges (`psr/log ^3.0`,
  `psr/event-dispatcher ^1.0`, `psr/simple-cache ^3.0`), so the package is no
  longer pinned to a single patch version.
- The install example in `docs/00-GETTING-STARTED.md` now uses `^0.1`.

## [0.1.0] - 2026-09-28

The first public release of Azera. It ships the complete MVC stack, the ORM and
query builder, and the supporting services, all built for PHP 8.2+.

### Added

- **Routing & dispatch** — `Router` with named routes, typed parameters,
  deferred specificity sorting and middleware; `Dispatcher` with a middleware
  pipeline; action-based controllers with dependency injection.
- **ViewEngine** — Clarity as the default template engine, plus adapters for
  Twig, Plates, Blade (`illuminate/view`) and Spiral Stempler, and plain PHP
  templates.
- **Database & ORM** — unified fluent `Query` builder for SELECT, INSERT,
  UPDATE, DELETE; Active Record models with state tracking and typed
  properties; `EntityManager` with metadata, casting and column-nullability
  policy; read/write splitting and connection pooling; schema introspection;
  bulk writes and composite keys.
- **Storage drivers** — PDO (MySQL, PostgreSQL, SQLite) and an optional MongoDB
  document store.
- **ORM performance** — two-level metadata cache (`clearL1()`/`clearL2()`),
  hydration fast paths, and strict-cast handling to cut per-request overhead.
- **HTTP utilities** — `Request`, `Response`, `Session`, `Cookies` and
  composable `Middleware`.
- **CLI** — `Console` with task auto-discovery, grouping, styled help and option
  parsing; the built-in `model-sync` task; and the `azera` binary.
- **Validation** — fluent field rules with type coercion and nested
  list/object validation.
- **Security** — CSRF tokens, password hashing, Sodium/OpenSSL encryption and a
  rate limiter.
- **Logging** — PSR-3 logging hooks for database and application events.
- **Events, cache, queues & AOP** — PSR-14 events, PSR-16 cache, a task queue,
  and attribute-driven aspect-oriented interceptors.
- **AppContext** — a service container that resolves lazily from the DI
  container.
- Documentation under `docs/`, a generated API reference under `docs/api/`,
  runnable examples, and published PHP-FPM and RoadRunner benchmark summaries.

[Unreleased]: https://github.com/sailantis/azera-framework/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/sailantis/azera-framework/releases/tag/v0.1.1
[0.1.0]: https://github.com/sailantis/azera-framework/releases/tag/v0.1.0
