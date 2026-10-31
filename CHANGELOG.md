# Changelog

All notable changes to `sailantis/azera-framework` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **SQL placeholder substitution** (`Db\Condition`) — a `?` inside an
  already-substituted value was re-read as a placeholder, letting input escape
  its string literal. Substitution is now a single pass that skips quoted
  literals and never re-scans inserted values.
- **Encrypted cookies** (`Http\Cookie`, `Http\Cookies`) — a failed decryption
  fell back to the raw `$_COOKIE` value, so plaintext was accepted. Failed
  decrypt/authenticate now returns the default; reading an unregistered cookie
  no longer dereferences null.
- **CSRF method override** (`Security\CsrfMiddleware`) — enforcement was decided
  from `Request::method()`, which honours `_method` and
  `X-HTTP-Method-Override`, so a cross-site `_method=GET` POST skipped the
  check. The transport method is now used.
- **View path traversal** (`Core\ViewEngine`) — absolute POSIX, Windows and UNC
  names were accepted and `..` was not canonicalised, allowing `include` outside
  the view root. Names are now confined to their root; absolute names are refused.
- **`extract()` shadowing** (`Core\Engines\NativeEngine`) — a view variable named
  `sourcePath` overwrote the template path before `include`. Rendering now uses
  a closure with `EXTR_SKIP`.
- **Identifier clauses** (`Db\Query`) — `orderBy()` validated only the first
  token and `columns()`/`groupBy()` validated nothing, so
  `orderBy('id (SELECT …)')` compiled verbatim. The whole term is now validated;
  use `Sql` nodes for expressions.
- **PostgreSQL array literals** (`Db\Sql`) — `pgArray()` did not escape single
  quotes.
- **AOP proxy cache** (`Aop\ProxyFactory`) — proxies were written to a
  predictable shared temp path and `require`d even when the write failed. The
  directory is now `0700` and checked for group/world writability, writes use
  `tempnam()` with an atomic rename, and files load only after a successful write.
- **SQLite introspection** (`Sync\Schema\SqliteSchemaProvider`) — PRAGMA
  statements interpolated table and index names unquoted.

### Changed

- **BREAKING** — `Cookie::encrypted()` requires an explicit key via
  `Cookie::key()`. The old default derived a key from `php_uname()`, which is
  predictable. Encrypting without a key throws.
- **BREAKING** — model-mode `orderBy()`, `columns()` and `groupBy()` reject
  string expressions. Use `Sql::raw()` or a `Sql` node instead. `Query::raw()`
  is unaffected.
- **Provider-based cookie encryption** (`Azera\Security\Crypt`) —
  `CipherProviderInterface`, `CipherRegistry`, `SodiumCipher` and `OpenSslCipher`
  replace the inlined algorithms. Payloads embed the provider id, and the cookie
  name is bound as associated data, so copied ciphertexts are rejected. Removed
  without shims: `Cookie::CIPHER_*`, `getAvailableCipher()`, `hasSodium()` and
  `hasOpenSSL()`. Select an algorithm with `->cipher(SodiumCipher::ID)` or via
  `CipherRegistry`.

### Added

- Regression tests for each finding above (`tests/Security/*RegressionTest.php`).
- `Azera\Security\Crypt`: pluggable AEAD providers, registry and self-describing payload format.

## [0.1.0]

The first public release of Azera. It ships the complete MVC stack, the ORM and
query builder, and the supporting services, all built for PHP 8.2+, together
with the packaging and tooling a published library is expected to carry.

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
- **`bin/azera`** is declared in `composer.json`, so Composer links it as
  `vendor/bin/azera` (with an `azera.bat` shim on Windows) for consuming
  projects. The documented CLI workflow works after `composer require`.
- Continuous integration (`.github/workflows/tests.yml`): the suite runs on
  PHP 8.2, 8.3 and 8.4, together with `composer validate --strict`.
- `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `.editorconfig` and
  `.gitattributes` (the latter trims development files from the package
  archive).
- Package metadata for Packagist: `keywords`, `homepage`, `support` and
  `authors`, plus `config.sort-packages`.
- Documentation under `docs/`, a generated API reference under `docs/api/`,
  runnable examples, and published PHP-FPM and RoadRunner benchmark summaries.

### Changed

- `sailantis/clarity-engine` is required at `^0.1` and resolved from Packagist
  instead of the local `dev-main` path repository. The path repository entry
  was removed: a `path` URL pointing at an absent directory makes
  `composer install` fail outright.
- PSR dependency constraints relaxed to caret ranges (`psr/log ^3.0`,
  `psr/event-dispatcher ^1.0`, `psr/simple-cache ^3.0`), so the package is no
  longer pinned to a single patch version.
- The install example in `docs/00-GETTING-STARTED.md` now uses `^0.1`.

### Fixed

- **AOP cache keys no longer depend on the filesystem layout.** The explicit
  `Pipeline` reflected an anonymous class to build the placeholder method it
  hands to interceptors, and an anonymous class name embeds its declaring file
  path. On Linux `getShortName()` has no backslash to split on, so the full
  absolute path leaked into `CacheInterceptor`'s key, pushed it past
  `ArrayCache`'s 64-character limit, and made every cached pipeline call throw
  `InvalidArgumentException: Cache key must be non-empty and at most 64
characters`. The pipeline now reflects a named class, and over-long keys are
  folded into a fixed-length digest instead of overflowing.

[Unreleased]: https://github.com/sailantis/azera-framework/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sailantis/azera-framework/releases/tag/v0.1.0
