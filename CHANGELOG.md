# Changelog

All notable changes to `sailantis/azera-framework` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Event dispatch caches priority-ordered listeners per event class**, refreshing
  the cache when `listen()` is called. Class-string listeners remain lazy, and
  cache size is bounded.
- **AOP now uses a shared `Invocation` model.** Interceptors implement
  `intercept(Invocation $invocation, callable $next): mixed`; pipelines compose
  once, and generated proxies reuse per-method plans. `Pipeline::call()` accepts
  the callable and arguments, with optional identity and advice; `Pipeline::handle()`
  is also available. Advice on an invocation takes precedence over constructor
  defaults. The interceptor and `Pipeline::call()` signatures changed;
  `ProxyFactory::setCurrent()` and `current()` were removed. `Pipeline::through()`
  remains as a deprecated immutable alias. See [docs/UPGRADING.md](docs/UPGRADING.md).
- `DatabaseManager::peek(string $role): ?Database` returns an instantiated
  connection without invoking its factory; the transaction interceptor uses it
  to check transactions across roles.
- **Cache keys use a typed, collision-resistant digest.** `CacheInterceptor`
  hashes the serialized identity, method and arguments with `xxh128`; derived
  keys are opaque and the change invalidates existing entries. Explicit
  `#[Cache(key: …)]` templates encode placeholder values distinctly by type;
  see Fixed for details.
- **`EntityManager::remove()` on an untracked entity with a complete key deletes
  it from storage.** Use `clear()` or `detach()` to remove an entity from identity
  tracking without deleting it.
- **`Orm\Concerns\EntityFacade`** consolidates finder and writer helpers for
  `Model` and `Document`. `Document` exposes backend-agnostic entity operations;
  SQL query-builder methods remain on `Model`. Plain classes using `#[Entity]`
  and `#[Column]` continue to work through the EntityManager.

### Added

- **Shared entity facade methods.** `Document` now provides the finders
  (`find()`, `findOrFail()`, `findOne()`, `findAll()`) and writer helpers
  (`create()`, `upsert()`, `firstOrCreate()`, `updateOrCreate()`) through
  `Orm\Concerns\EntityFacade`, using the configured store.

### Fixed

- **Keyless entity metadata and persistence.** `Metadata::pkFields` now includes
  only declared key columns and is empty when a class has no key; the metadata
  version was bumped to `v11`. Key-dependent operations validate the key and
  report the class, while inserts of keyless entities use plain SQL inserts and
  synthetic heap slots. Keyless `upsert()` falls back to insert. This also avoids
  phantom SQL/Mongo identities and prevents multiple pending entities from sharing
  one heap node.
- **Explicit cache-key placeholders.** Values now produce distinct, typed,
  key-safe tokens, including serialized arrays and objects; closures and resources
  raise an error when they cannot be serialized deterministically. Placeholder
  validation now checks the template itself, so argument values containing
  `{name}` do not trigger false unresolved-placeholder errors.
- **Proxy generation and AOP runtime behavior.** Proxies now support cloning,
  serialization and by-reference variadic parameters; terminal closures are
  created per call. Interceptor registries are scoped per `ProxyFactory`, plans
  refresh when interceptors are registered, and `AopPlan` is PSR-4 loadable.
- **Transaction interception.** Leak checks now run before commit and roll back
  on failure. Existing transactions on other roles are recognized, and an
  implicit transaction role uses the manager's default connection.
- **Store-aware queries and entity diagnostics.** Model-backed SQL queries now
  reject entities assigned to another store with guidance to use that store.
  `Model::findAll()` uses the EntityManager; ID mismatch errors identify the class
  and expected and actual counts.
- **Entity identity and deletion.** `adopt()` and document deletion now validate
  every required key field and identify missing fields. Untracked entities with
  complete keys are adopted before removal, so deletion reaches the configured
  store. Operations requiring a primary key report the class and key requirements.
- **ORM example corrections.** `ModelLoadMethodsExample.php` now wraps raw
  column expressions in `Sql::raw()` and seeds records with IDs used by its
  finder examples.

## [0.6.0] - 2026-11-24

This **minor** release adds compiled route matching, request handling, localization,
session and response features, alongside routing and locale fixes.

It requires `sailantis/clarity-engine` **0.6.0**, whose `escape`/`esc`/`e` output
markers now escape once instead of twice. Templates that compensated for the old
double escaping must drop the workaround; compiled caches recompile on upgrade.

### Added

- **Compiled router index.** `Router::match()` combines compatible routes into
  per-method PCRE groups of up to 128 alternatives; other routes retain
  specificity-ordered matching. In a 60-route bucket, matching the last route
  measured **277 µs → 8.1 µs**. `benchmarks/router-match.php` reports candidate and
  compilation costs (`-d xdebug.mode=off`).
- **`Router::allowedMethods()`** returns matching methods or `null` when the URI has
  no route, distinguishing 405 from 404 without changing `match()`'s return type.
- **Request kernel and handlers.** Optional `Azera\Core\Kernel` coordinates routing,
  dispatch, 404/405 responses, and exception handling without writing output.
  `NotFoundHandlerInterface` and `ErrorHandlerInterface` provide customizable
  policies, with plain handlers registered by default on `AppContext`.
- **Localization.** `AppContext::translator()` provides shared translation with
  locale precedence, domain scopes, fallback lookup, placeholders, and engine
  binding. `LoaderInterface`, `TranslationLoaderBridge`, and `LocaleNegotiator`
  integrate Clarity loaders and `Accept-Language`; request scopes reset between
  worker requests. `Validator::setTranslations()`, `Controller::translator()` and
  `Controller::t()` provide framework integration. See the new
  [Localization guide](docs/12-LOCALIZATION.md).
- **Locale-aware formatting.** `AppContext::intl()` delegates to Clarity's
  `IntlFormatter` for number, currency, date, time, text and ICU message
  formatting. The framework and template filters share the locale stack;
  formatting is also available outside templates. Clarity bridge modules register
  the filters and locale block. See the [Localization guide](docs/12-LOCALIZATION.md)
  and updated validation, controller and Clarity documentation.
- **`Cookie::sameSite()`**, plus a `$sameSite` argument on `Cookie::create()` and
  `Cookies::set()` adds the `SameSite` cookie attribute with canonical casing and
  validates that `SameSite=None` is paired with `Secure`.
- **Session lifecycle methods:** `Session::regenerateId()`, `Session::destroy()`,
  `Session::isActive()` and `Session::id()`.
- **`Azera\Http\Flash`** and **`AppContext::flash()`** / **`Controller::flash()`** —
  provide queued and immediate messages, consumption and escaped rendering. Storage
  uses the session when available and request-scoped memory otherwise.
- **Response caching and file delivery.** `Response` adds conditional GET support
  (ETag and modification-time validators) and file responses with metadata,
  streaming, and optional `X-Sendfile` support.

### Changed

- **`Controller::flash()`** is public and returns `Azera\Http\Flash`. Existing
  controller methods with the same name must be renamed or adapted; use
  `$this->flash()->error($message)` to queue a message or
  `$this->flash()->now($type, $message)` for immediate display.
- **Route definition and matching rules:** UUID parameters require the canonical
  hexadecimal `8-4-4-4-12` form; static segments after a wildcard are required
  suffixes, and non-static segments cannot follow a wildcard; unknown parameter
  types raise an error when the route is added. A trailing wildcard continues to
  match zero or more segments.
- The compiled index is derived at runtime and excluded from route caches; needed
  buckets are rebuilt on the first match after cache restoration.
- **`getAllowedMethods()`** includes synthesized `OPTIONS` when auto-OPTIONS is
  enabled and returns registered methods beyond the standard list, ordered after
  standard methods.
- `sailantis/clarity-engine` is now required at `^0.6` or `dev-main` for
  `IntlFormatter` and the escaping markers. Run `composer update
  sailantis/clarity-engine` to update a conflicting lock file.

### Fixed

- `setRevalidateFreq()` without `cached()` now retains the route table.
- Auto-OPTIONS no longer recurses through `getAllowedMethods()`, which walks the
  compiled index once to find matching methods.
- Route matching and method lookup share URI decoding for percent-encoded static
  segments; encoded slashes retain their segment boundaries.
- `$ctx->intl()` follows the translator's configured locale, including when set
  after the formatter is resolved.

### Performance

| Case | Before | After |
| --- | ---: | ---: |
| typed match, 20-route bucket | 94.7 µs | 5.5 µs |
| typed match, 60-route bucket | 277 µs | 8.1 µs |
| typed match, 200-route bucket | 740 µs | 12.7 µs |
| static match | 1.6 µs | 1.8 µs |
| 404 | 17.6 µs | 2.3 µs |
| 405 / `getAllowedMethods()` | 197 µs | 8.5 µs |
| first match, 300-bucket table | 32 µs | 46 µs |

Indexes compile on demand, so the first match compiles only the buckets it uses.

## [0.5.1] - 2026-11-18

A **patch** release. Header validation — commented out since 0.5.0's predecessor — is
restored behind `Response::strict()`, off by default, and the header API now has tests.

### Added

- `Response::strict()` enables header validation, which is **off by default**. This is a
  development assertion, not a security control: PHP's `header()` rejects CR/LF, and
  PSR-7 adapters reject malformed headers during construction. The check adds an
  error that identifies the offending field when application code builds an invalid
  header, such as `'Location: ' . $url` or a name containing spaces.

  Set it once at startup using the application's debug switch. It is process-wide,
  so resident workers share the setting across requests. When disabled, it adds one
  static read per header. Validation covers both name/value setters and complete
  `Name: value` lines, including `new Response(headers: ['ETag: ' . $etag])`.
  Invalid names (not RFC 9110 tokens) and values containing CR, LF, or NUL raise
  `InvalidArgumentException` when strict mode is enabled.

### Fixed

- **Header validation was silently disabled.** The guard had been commented out
  while the documentation still promised that malformed names and values raise
  `InvalidArgumentException`. It is now gated by `Response::strict()` rather than
  removed, and covered by `tests/Http/ResponseHeaderTest.php` — the header API had
  no tests at all, which is why the guard could be dropped without a failure.
- **Documentation no longer claims validation that is off by default.** The
  `addHeader()` `@throws` tag and the API doc described a guard that never ran.

## [0.5.0] - 2026-11-18

A **minor, breaking** release. Response headers are now stored by field name, so repeated
fields such as `Set-Cookie` survive; cookies are emitted through the response instead of
`setcookie()`; and the middleware pipeline builds in two stages so global and route-group
middleware run before the controller does.

### Fixed

- **Global and route-group middleware now run before controller construction and
  action resolution.** If they allow the request, `Dispatcher` constructs the
  controller, runs controller and action middleware, then reflects the action and
  resolves its arguments. Rejected requests no longer reveal whether an action exists
  through a 404-versus-redirect difference; unknown actions are reported only after
  middleware allows the request.
- **Unknown controller classes now raise `ControllerNotFoundException`.** The previous
  `AppContext::get()` path raised `RuntimeException("Service not found: ...")`,
  despite `dispatch()` declaring the former in `@throws`.
- **Incoming cookies are read from `Request`, not `$_COOKIE`.** `Cookies`, `Cookie`,
  and `CsrfMiddleware` can now read cookies when workers disable CGI globals with
  `Worker::withoutCgiGlobals()`. Previously, they returned the caller's default if
  `$_COOKIE` was unavailable.
- **Worker responses now include `Set-Cookie` headers.** `setcookie()` does not add
  response headers under worker SAPIs, so cookies were silently dropped. `Dispatcher`
  now adds jar cookies to responses, and repeated header values such as multiple
  `Set-Cookie` headers are preserved.
- **Auto-`OPTIONS` responses now include the `Allow` value.** A call to
  `addHeader('Allow', $methods)` silently dropped the methods, leaving a bare header
  that clients reject.

### Added

- `Request::cookies()` and `Request::cookie()`.
- `Cookie::create()` (replacing `make()`), `toHeader()`, `isDirty()`, and `setRaw()`.
- `Cookies::applyTo()` and `Cookies::clear()`.
- `Response::addHeader()`, `addHeaders()`, `addCookie()`, `setHeader()`,
  `setHeaders()`, `getHeaderValues()`, and `getHeaderLines()`.

### Changed

- **BREAKING:** `Dispatcher::buildPipeline()` now takes a controller class
  (`string $controllerClass`) instead of an instance and no longer receives resolved
  action parameters. It builds the global and route-group stages only.
  `buildActionPipeline(Controller $controller, string $action, array $vars,
AppContext $context)` builds the controller and action stages and resolves action
  arguments. Subclasses overriding `buildPipeline()` must be updated.
- Controller and action middleware declarations are read from the controller
  _instance_. A subclass that redeclares `$middlewares` replaces the parent's list;
  it does not extend it. This behavior is unchanged.
- **BREAKING:** `Cookie::make()` is now `Cookie::create()`, following the
  `Model::create()` factory convention. `make()` has been removed without an alias.
- **BREAKING:** `Response::getHeaders()` now returns a map from field names to value
  lists (for example, `['Content-Type' => ['application/json']]`), matching PSR-7's
  shape and preserving repeated values such as `Set-Cookie`. Use `getHeader($name)`
  for one value, `getHeaderValues($name)` for all values, and `getHeaderLines()` for
  formatted lines such as `'Content-Type: application/json'`.
- **BREAKING:** `Response::setHeader()` and `addHeader()` now take a field name and
  value; `addHeader()` still accepts a complete `Name: value` line as its first
  argument. `setHeader()` replaces a field's values; `addHeader()` appends one.
  `setHeaders()` and `addHeaders()` accept lists, with `setHeaders()` replacing the
  field's full list. The constructor and `json()`, `html()`, `text()`, and `redirect()`
  factories now take a name-keyed header map (lists of lines are still accepted).
- `Response::send()` now preserves repeated field values by passing `replace: false`
  for the second and later values. Previously only the last `Set-Cookie` was written.
- `Response::send()` no longer emits headers after `headers_sent()`.
- **BREAKING:** `Cookie::send()` and `Cookies::sendAll()` have been removed.
  `setcookie()` has no effect under workers and would duplicate headers under
  `php-fpm`; responses are now the only cookie emission path. `Cookie::delete()` now
  returns `static` and queues a past-expiry header.
- Only **written or modified** cookies are emitted. Re-emitting a read cookie with
  default attributes could change its expiry, path, or security settings.
- Changing an attribute such as `expires()` or `secure()` on a request cookie emits
  its existing value with the updated attributes. `Cookie::toHeader()` gets that value
  through `value()`, which decrypts the raw incoming value unless `set()` replaced it.
- `Cookies` now needs a `Request` to read incoming cookies; `AppContext` supplies it.
  `new Cookies()` creates a jar with no incoming cookies.

## [0.4.2] - 2026-11-09

Installed by `composer require` from this tag. A **minor** release with no breaking changes. Updated product images and documentation.

## [0.4.1] - 2026-11-09

Installed by `composer require` from this tag. A **minor** release with no breaking changes. Condensed v0.4.0 comments and changelog entries.

## [0.4.0] - 2026-11-09

Installed by `composer require` from this tag. A **minor, breaking** release: route handlers
and middleware now require strings. It also fixes a PHP 8.5 fatal in v0.3.1.

### Added

- **Route cache.** Declaring routes inside `cached($files, $closure)` compiles the route
  table to PHP. It enables caching automatically; the first router read loads a fresh
  table or rebuilds and writes a stale or missing one. The 120-route homepage build drops
  from 191 µs to 5.7 µs; nginx + php-fpm `/echo` throughput at c=96 rises from 23,814 to
  49,174 rps.

  Declared source files are checked for edits, additions, and removals, including edits
  within the same second. `setRevalidateFreq()` bounds staleness in long-lived workers.
  `azera routes:cache|clear|status|list` supports deploys, CI, and inspection.

- `Router::setCachePath()`, `disableCache()`, `cachedSources()`, `cachePath()`,
  `cacheStatus()`, `compileCache()`, `clearCache()`, `setRevalidateFreq()`,
  `getRevalidateFreq()`, `warnOnWriteFailure()`.

  By default, the table path hashes declared sources and effective UID under
  `sys_get_temp_dir()/azera_routes/`. The directory is `0700`, files are `0600`, and
  group/world-writable directories are refused because tables are loaded with `require`.

### Changed

- **Route handlers and middleware must be strings.** Other values throw
  `InvalidArgumentException` even without caching; dispatch treats them as names, and
  the route table must remain serializable.
- `cached()` accepts `string|array $files` and defers route registration.
- Routes outside cached blocks are rejected, both before the first block and after the
  table is read, so no route is silently omitted from a rebuild.

### Removed

- `Router::enableCache()` and `enableCacheLazy()`. Declaring a unit enables caching;
  `disableCache()` opts out, while `setCachePath(null)` selects the default path. Lazy
  read-only mode was removed because writes are non-fatal and failures can be reported
  with `warnOnWriteFailure()`.
- `Router::recordSources()`, `recordedSources()`, `markCompiled()`, `restoreIfCached()`,
  `forget()`. `cached()` declares source files explicitly instead of recording them.
- `RoutesTask::verifyAction()`. Stale tables rebuild on read; use
  `routes:status --strict` in CI instead.

### Fixed

- **PHP 8.5 compatibility.** Removed `#[\Attribute]` from abstract `Azera\Aop\Advice`
  (concrete subclasses retain it) and removed 25 deprecated
  `ReflectionProperty::setAccessible()` calls, including in console help. The suite has
  no PHP 8.5 deprecations; CI now tests PHP 8.2–8.5. v0.3.1 fatals on PHP 8.5.

## [0.3.1] - 2026-11-08

Installed by `composer require` from this tag. A patch release: no API removed and no
behaviour changed, only the dynamic-route fatal error below.

**If you are on v0.3.0, upgrade.** That tag ships the bug — any route that has both a path
variable and an action taking an injected service fails on every request with
`Serialization of 'Closure' is not allowed`.

### Fixed

- **Dynamic routes no longer fail when an action receives a closure-backed service.**
  The route cache serialized invocation `$params`, including injected services; a
  closure in a service caused `Serialization of 'Closure' is not allowed` when the
  route also had path variables (for example, the docs route with `DocsService`).

  Invocation parameters are no longer cached. They are rebuilt for each dispatch to
  avoid stale request-scoped services; `ResolvedRoute::$params` remains empty, and
  route variables are available through `->vars`.

  Regression tests in `tests/Mvc/DispatcherCacheTest.php` cover the failure and were
  mutation-checked against the original implementation.

## [0.3.0] - 2026-11-07

Installed by `composer require` from this tag. Carries three BREAKING changes and ten security
fixes — read _Security_ and _Changed_ below before upgrading.

This is the first release since 0.1.0. There was no 0.2.0: the number is skipped, not lost. It
requires `sailantis/clarity-engine` `^0.4` (or `dev-main`), and before this the bare `dev-main`
constraint made the package uninstallable by a project on Composer's default stability.

### Security

- **SQL placeholder substitution** (`Db\Condition`): A `?` in a substituted value could be mistaken for a placeholder, escaping its string literal. Substitution now skips quoted literals in one pass.
- **Encrypted cookies** (`Http\Cookie`, `Http\Cookies`): Failed decryption now returns the default instead of the raw cookie value; reading an unregistered cookie no longer dereferences null.
- **CSRF method override** (`Security\CsrfMiddleware`): Enforcement now checks the transport method, not the method overridden by `_method` or `X-HTTP-Method-Override`.
- **View path traversal** (`Core\ViewEngine`): View names are canonicalised and confined to their root, including POSIX, Windows, and UNC paths.
- **`extract()` shadowing** (`Core\Engines\NativeEngine`): Rendering uses a closure with `EXTR_SKIP` to prevent view variables from overwriting the template path.
- **Identifier clauses** (`Db\Query`): `orderBy()`, `columns()` and `groupBy()` validate the whole term. Use `Sql` nodes for expressions.
- **PostgreSQL array literals** (`Db\Sql`): `pgArray()` escapes single quotes.
- **AOP proxy cache** (`Aop\ProxyFactory`): proxies are written to a `0700` directory that is checked for group and world writability. Writes use `tempnam()` with an atomic rename, and files load only after a successful write.
- **SQLite introspection** (`Sync\Schema\SqliteSchemaProvider`): PRAGMA statements quote table and index names.

### Changed

- **The clarity-engine constraint no longer blocks a stable install.** `require` said bare
  `dev-main`, and a dependency on `dev-main` forces every consumer's `minimum-stability` to
  `dev` — so a project on the default `stable` could not install this package at all
  (`… requires sailantis/clarity-engine dev-main … does not match your minimum-stability`).
  It is now `dev-main || ^0.4`, which keeps local development on `dev-main` while letting a
  stable project resolve the tagged release.
- **BREAKING:** `Cookie::encrypted()` requires an explicit key via `Cookie::key()`. The former default derived the key from `php_uname()`, which is predictable. Encrypting without a key throws.
- **BREAKING:** Model-mode `orderBy()`, `columns()` and `groupBy()` reject string expressions. Use `Sql::raw()` or a `Sql` node instead. `Query::raw()` is unaffected.
- **Cookie cipher API:** Removed without shims: `Cookie::CIPHER_*`, `getAvailableCipher()`, `hasSodium()` and `hasOpenSSL()`. Select an algorithm with `->cipher(SodiumCipher::ID)` or through `CipherRegistry`.

### Added

- **Cookie cipher providers** (`Azera\Security\Crypt`): pluggable AEAD providers (`CipherProviderInterface`, `CipherRegistry`, `SodiumCipher`, `OpenSslCipher`) and a self-describing payload format. The cookie name is bound as associated data, so copied ciphertexts are rejected.
- Regression tests for each security fix (`tests/Security/*RegressionTest.php`).

### Performance

- **`Request::header()` caches header-name mappings** (`Http\Request`), reducing lookup time from **0.234 µs to 0.083 µs** (−64%) and `/echo` PHP CPU by **0.43 µs/request** (10.75 → 10.32 µs). Only the mapping is cached—not the request-specific value—to prevent cross-request leakage.
- **Prepared statements are pooled per connection** (`Db\Database`), opt-in via `Database::useStatementCache()`. Statements are reused after they are no longer reachable, avoiding repeated SQL parsing and planning (about 4.6% of busy pdo_sqlite worker samples).

  Measured on PHP 8.5.11 with `opcache.jit=tracing`, interleaved:

  |                                             | before   | after                 |
  | ------------------------------------------- | -------- | --------------------- |
  | one `SELECT … LIMIT 20` through `ResultSet` | 13.45 µs | **9.74 µs** (−27.6%)  |
  | the same in a full `/api/items` dispatch    | 28.94 µs | **23.82 µs** (−17.7%) |

  **One available statement per SQL.** A statement is removed from the pool while in use, so concurrent readers never share a cursor. It is returned only when its `ResultSet` is destroyed, not on `closeCursor()`. Regression tests cover both rules (`tests/Db/StatementCacheTest.php`).

  **Off by default.** The benefit is unproven for emulated prepares (the `pdo_mysql` default); enable only after measuring. `connect()` flushes the pool because statements belong to their connection.

- **Route resolution is cached** (`Core\Router`): Each distinct handler is parsed once (including separate array and `null` forms), reducing `match()` from **0.53 µs to 0.28 µs**.
- **The dispatcher reuses stable data** (`Core\Dispatcher`): Cache action reflection and reuse readonly `ResolvedRoute` objects. Dynamic-route cache entries include variables and are capped at 256.
- **Routes without middleware call the handler directly** (`Core\Dispatcher`), avoiding an unnecessary closure chain.
- Scope: These microbench gains are throughput-neutral in served requests: dispatch is ~3 µs of a ~200 µs request, and the ~0.3 µs saved is below measurement noise (±4%).

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

[Unreleased]: https://github.com/sailantis/azera-framework/compare/v0.5.1...HEAD
[0.5.1]: https://github.com/sailantis/azera-framework/releases/tag/v0.5.1
[0.5.0]: https://github.com/sailantis/azera-framework/releases/tag/v0.5.0
[0.4.2]: https://github.com/sailantis/azera-framework/releases/tag/v0.4.2
[0.4.1]: https://github.com/sailantis/azera-framework/releases/tag/v0.4.1
[0.4.0]: https://github.com/sailantis/azera-framework/releases/tag/v0.4.0
[0.3.1]: https://github.com/sailantis/azera-framework/releases/tag/v0.3.1
[0.3.0]: https://github.com/sailantis/azera-framework/releases/tag/v0.3.0
[0.1.0]: https://github.com/sailantis/azera-framework/releases/tag/v0.1.0
