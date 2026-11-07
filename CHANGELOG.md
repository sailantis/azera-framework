# Changelog

All notable changes to `sailantis/azera-framework` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **SQL placeholder substitution** (`Db\Condition`): a `?` inside a substituted value was re-read as a placeholder, which let input escape its string literal. Substitution now runs in a single pass that skips quoted literals.
- **Encrypted cookies** (`Http\Cookie`, `Http\Cookies`): a failed decryption fell back to the raw `$_COOKIE` value. Failed decryption now returns the default, and reading an unregistered cookie no longer dereferences null.
- **CSRF method override** (`Security\CsrfMiddleware`): enforcement used `Request::method()`, which honours `_method` and `X-HTTP-Method-Override`. The transport method is now used.
- **View path traversal** (`Core\ViewEngine`): absolute POSIX, Windows and UNC names were accepted, and `..` was not canonicalised. Names are now confined to their root.
- **`extract()` shadowing** (`Core\Engines\NativeEngine`): a view variable named `sourcePath` overwrote the template path. Rendering now uses a closure with `EXTR_SKIP`.
- **Identifier clauses** (`Db\Query`): `orderBy()`, `columns()` and `groupBy()` validate the whole term. Use `Sql` nodes for expressions.
- **PostgreSQL array literals** (`Db\Sql`): `pgArray()` escapes single quotes.
- **AOP proxy cache** (`Aop\ProxyFactory`): proxies are written to a `0700` directory that is checked for group and world writability. Writes use `tempnam()` with an atomic rename, and files load only after a successful write.
- **SQLite introspection** (`Sync\Schema\SqliteSchemaProvider`): PRAGMA statements quote table and index names.

### Changed

- **BREAKING:** `Cookie::encrypted()` requires an explicit key via `Cookie::key()`. The former default derived the key from `php_uname()`, which is predictable. Encrypting without a key throws.
- **BREAKING:** Model-mode `orderBy()`, `columns()` and `groupBy()` reject string expressions. Use `Sql::raw()` or a `Sql` node instead. `Query::raw()` is unaffected.
- **Cookie cipher API:** Removed without shims: `Cookie::CIPHER_*`, `getAvailableCipher()`, `hasSodium()` and `hasOpenSSL()`. Select an algorithm with `->cipher(SodiumCipher::ID)` or through `CipherRegistry`.

### Added

- **Cookie cipher providers** (`Azera\Security\Crypt`): pluggable AEAD providers (`CipherProviderInterface`, `CipherRegistry`, `SodiumCipher`, `OpenSslCipher`) and a self-describing payload format. The cookie name is bound as associated data, so copied ciphertexts are rejected.
- Regression tests for each security fix (`tests/Security/*RegressionTest.php`).

### Performance

- **Prepared statements are pooled per connection** (`Db\Database`), opt-in via
  `Database::useStatementCache()`. `query()` prepared the statement on every call, so the
  SQL text was parsed and planned again each time — on pdo_sqlite that was
  `sqlite3_prepare_v2` plus `sqlite3RunParser`, measured at ~4.6% of a busy worker's
  samples. A statement is now taken from the pool when executed and offered back when it
  is no longer reachable.

  Measured on PHP 8.5.11 with `opcache.jit=tracing`, interleaved:

  | | before | after |
  | --- | --- | --- |
  | one `SELECT … LIMIT 20` through `ResultSet` | 13.45 µs | **9.74 µs** (−27.6%) |
  | the same in a full `/api/items` dispatch | 28.94 µs | **23.82 µs** (−17.7%) |

  **The pool is a one-slot-per-SQL pool, not a cache, and that is a correctness
  requirement.** A cache keyed by SQL hands the same `PDOStatement` to two successive
  queries; the second `execute()` rewinds the first caller's cursor and a partially
  consumed reader silently continues with someone else's rows. Taking a statement
  *removes* it, so a second query on the same SQL while the first is alive prepares its
  own and two live readers can never share a cursor. For the same reason a statement is
  released only from `ResultSet::__destruct()`, never from `closeCursor()`: a
  `ResultSet` that has closed its cursor is still alive and its `fetch()` must return
  `false`, which the earlier release-on-closeCursor() version broke by handing back the
  statement while it was still fetchable. Both invariants have regression tests
  (`tests/Db/StatementCacheTest.php`), each verified to fail when its rule is removed.

  **Off by default.** On a driver that emulates prepares (`pdo_mysql`'s default) the
  client-side substitution happens at execute time and the win is unproven; a deployment
  should enable it where it has measured the difference. The pool is flushed on
  `connect()`, because statements belong to the handle that prepared them.
- **Route resolution is cached** (`Core\Router`): `resolveHandler()` parses a handler string into a namespace / controller / action triple. That parse is a pure function of the handler, but it ran on every request of every static route — 0.41 µs of the router's 0.53 µs. It is now parsed once per distinct handler value, including the array and `null` forms (which are cached in separate slots). `match()` drops from 0.53 to 0.28 µs.
- **The dispatcher stops rebuilding what cannot change** (`Core\Dispatcher`): the `ReflectionMethod` for an action is cached per `class::action` (0.10 µs per request), and one `ResolvedRoute` instance is reused per route identity. `ResolvedRoute` is all-readonly, so the same object can be handed to `setRoute()` for every request that matches the same route. Static routes key on the three names alone; dynamic routes include their variables and are bounded at 256 entries, so walking `/items/{id}` cannot grow the cache without limit in a persistent worker.
- **A route with no middleware runs its handler directly** (`Core\Dispatcher`): `buildPipeline()` used to build a zero-link closure chain and call it once per request. It now returns the core handler.
- Scope: these are throughput-neutral at the served level. Dispatch is ~3 µs of a ~200 µs request on a warm worker, so ~0.3 µs saved is ~0.15% — below served-measurement noise (±4%), and an interleaved A/B confirms no difference. The gain is bounded per-request work, not a benchmark result.

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
