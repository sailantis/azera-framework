# Changelog

All notable changes to `sailantis/azera-framework` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

A **minor** release with two themes: the router now uses a compiled index instead of
scanning routes one at a time, and several long-standing bugs are fixed. The behavior
corrections are listed under *Changed*; they fix unintended behavior rather than add
new rules.

### Added

- `Router::match()` now uses a compiled index for each method bucket. Routes that can
  be expressed as PCRE are combined into patterns of up to 128 alternatives. Matching
  200 such routes takes two `preg_match` calls instead of 200 token walks. Matching
  the last route in a 60-route bucket improved from **277 µs to 8.1 µs**. Routes that
  cannot be combined—such as optional segments, `regex(…)` types, and custom
  validators—keep their behavior and are interleaved by specificity, so they do not
  slow the fast path for other routes.
- `benchmarks/router-match.php` reports cost per candidate, the named real-world cases,
  and the initial compile cost. Run it with `-d xdebug.mode=off`.
- **`Router::allowedMethods()`** returns the methods that would match a URI, or `null`
  when the URI matches no route at all. It is how a caller distinguishes a 404 from a
  405 without adding a status field to the router: `null` is a 404, a non-empty list is
  a 405 whose `Allow` header is that list. The result is never `[]`, so one branch
  covers both. `match()` keeps its `?array` return and its signature — asking for the
  distinction is opt-in and costs nothing for callers that do not ask.
- **`Azera\Core\Kernel`** (optional) coordinates `Router` and `Dispatcher` for one
  request: it dispatches a match, answers a miss through `NotFoundHandlerInterface`,
  answers a known path with the wrong method as `405` + `Allow`, and answers an
  exception through `ErrorHandlerInterface`. `Kernel::handle()` never writes output, so
  the same call serves FPM and a resident worker. `Router::match()` and
  `Dispatcher::dispatch()` remain public and independently callable — the Kernel adds a
  boundary, it does not become the only way in.
- **`NotFoundHandlerInterface`** / **`ErrorHandlerInterface`**, with
  `PlainNotFoundHandler` and `PlainErrorHandler` as defaults registered on
  `AppContext`. One policy now serves every entry point instead of each transport
  carrying its own copy. They are separate interfaces so a 404 policy cannot answer a
  `TypeError`.
- **`Azera\Localization\Translator`** — one translation service for the whole
  application, resolved as `$ctx->translator()` and independent of the view engine.
  It owns locale precedence (per call → `withLocale()` scope → negotiated request
  locale → configured → `LocaleService` default → detected), the domain stack,
  fallback-locale lookup and `{placeholder}` substitution. A missing key echoes
  instead of producing a blank, and `getOrDefault()` distinguishes the two.
  `bind($view)` registers translation on the active engine: the `t` filter and both
  blocks for Clarity, a `t()` function for `NativeEngine`, a filter for adapters.
  `resetState()` clears the request scopes in resident workers, wired through the
  existing `RequestScoped` hook in `AppContext::clearRequestScope()`.
- **`Azera\Localization\LoaderInterface`** and **`TranslationLoaderBridge`** — a
  loader seam identical to Clarity's single-method contract, so Clarity's
  `FileTranslationLoader`, `ArrayTranslationLoader`, `ChainTranslationLoader` and
  `RedisCachingLoader` are reused rather than reimplemented. The bridge satisfies both
  interfaces, so one loader serves either side.
- **`Azera\Localization\LocaleNegotiator`** — pure `Accept-Language` matching with
  exact, primary-subtag and `*` handling. `Translator::negotiate($request, …)` adopts
  the result.
- **`Azera\Core\Engines\LocalizationModule`** — the Clarity bridge. It registers the
  `t` filter and the `{% with_locale %}` / `{% with_t_domain %}` blocks through the
  trait API and points them at the framework translator, so `{{ "k" |> t }}` and
  `$translator->get('k')` share one catalogue. The locale stack is shared in both
  directions: an existing `'locale'` service is adopted, otherwise the translator's
  is installed.
- **`Validator::setTranslations(Translator $translator, string $domain = 'validation')`** —
  error codes become catalogue keys, with the built-in English template as the
  fallback for codes a catalogue omits. `setTranslator()` remains and still wins when
  registered last.
- **`Controller::translator()`** and **`Controller::t()`** — the translation service
  and a one-call shortcut next to `view()` and `session()`.
- **`Azera\Localization\Intl`** — locale-aware formatting for the whole
  application, resolved as `$ctx->intl()`: numbers, currency, percentages,
  scientific notation, spellout, ordinals, dates, times, relative time,
  transliteration, ICU messages and locale information. It delegates to
  clarity-engine's new `IntlFormatter`, so a controller's
  `$intl->formatCurrency()` and a template's `|> format_currency` are one call —
  no ICU logic is duplicated, and both degrade identically without `ext-intl`.
  `bind($view)` registers the fourteen `format_*` filters and four `locale_*`
  functions. It shares the translator's `LocaleService`, so a locale scope moves
  formatting and messages together.
- **`Clarity\Localization\IntlFormatter`** (clarity-engine) — the engine-free
  formatting layer `IntlFormatModule` is now built on. The module keeps its
  filters, signatures and output; it delegates instead of owning the code, which
  is what lets a framework expose the same formatting outside templates.
- **`Azera\Core\Engines\IntlModule`** and **`LocaleBlocks`** — the Clarity bridge
  for formatting, and the shared installer for the locale stack and the
  `{% with_locale %}` block. `LocaleBlocks` declares the block at most once per
  engine, so binding both the translator and the intl service is safe.
- Documentation: new [Localization](docs/12-LOCALIZATION.md) guide, plus sections in
  the validation, controller and Clarity guides. The Clarity guide's method table
  listed a non-existent `use(ModuleInterface)`; it is now `addModule()`.

### Changed

- **`{param:uuid}` now matches only the canonical `8-4-4-4-12` hexadecimal form.**
  The previous validator checked four hyphen positions, then called `ctype_xdigit()`
  on the remaining characters. It therefore accepted malformed values such as
  `123e4567-e89b-12d3-a456-42661417400-`. Routes relying on that behavior no longer match.
- **Static segments after a wildcard are a required suffix.** `/docs/{path:*}/edit` used
  to ignore `/edit`, matching `/docs/a/b/wrong` with `path = [a, b, wrong]`. It now
  matches `/docs/a/b/edit` with `path = [a, b]` and rejects paths without the `/edit`
  suffix. A wildcard at the end of a route still matches zero or more segments.
- **Unknown parameter types now throw when a route is added.** Previously, `{id:int?}`
  (the `?` belongs on the name) registered successfully, then raised
  `Unknown validator: int?` during a matching request.
- The compiled index is derived state and is not stored in the route cache. Existing
  cached route tables remain valid; the router rebuilds each needed index bucket on
  the first match after restoring the cache.
- **`getAllowedMethods()` now reports `OPTIONS`** when `autoOptions()` is enabled, the
  path exists, and no explicit `OPTIONS` route is registered. Previously only `match()`'s
  auto-OPTIONS branch added it, so the same URI produced two different `Allow` values
  depending on which method was asked. Both now share one synthesis point.
- **`getAllowedMethods()` reports methods registered outside its known list.** It
  iterated a fixed set of seven methods, so a route registered as `PROPFIND` was matched
  by `match()` but never appeared in `Allow`. The list is now derived from the table:
  known methods in their documented order, then anything else alphabetically.
- **`sailantis/clarity-engine` is now required at `^0.5` (or `dev-main`).** The new
  `Azera\Localization\Intl` delegates to Clarity's `IntlFormatter`, which first ships in
  0.5.0. The previous `^0.4` range allowed a stable install of 0.4.x, where the class
  does not exist, so `$ctx->intl()` failed with `Class "Clarity\Localization\IntlFormatter"
  not found`. Composer will not resolve a conflicting lock: run `composer update
  sailantis/clarity-engine`.

### Fixed

- **`setRevalidateFreq()` without `cached()` emptied the route table.**
  `ensureFlushed()` cleared the table but had no declared units to rebuild. As a result,
  the first request matched and the second returned 404.
- **`autoOptions(true)` with an `OPTIONS` request recursed until memory ran out.**
  `match()` delegated unmatched `OPTIONS` requests to `getAllowedMethods()`, which
  called `match()` for `OPTIONS` again. `getAllowedMethods()` no longer calls `match()`.
- **`getAllowedMethods()` no longer runs seven full matches.** It walks the compiled
  index once to find every matching method, reducing the measured time from 197 µs to
  under 15 µs.
- **A percent-encoded URI matched inconsistently.** Whether `/a%20b/7` matched
  `/a b/{id:int}` depended on whether the route happened to be inlinable: the combined
  regex matched the raw subject, while the token scan used the decoded segments. Route
  matching, `allowedMethods()` and `getAllowedMethods()` now share one URI-preparation
  step, so they cannot disagree, and a static route registered as `/a b` is reachable as
  `/a%20b`. A decoded `%2F` falls back to walking the candidates rather than inventing a
  segment boundary the client never sent.
- A wildcard followed by a non-static segment is rejected at registration instead of
  leaving the split between parameters ambiguous at match time.

### Performance

| Case | Before | After |
| --- | --- | --- |
| typed match, 20-route bucket (last candidate) | 94.7 µs | 5.5 µs |
| typed match, 60-route bucket (last candidate) | 277 µs | 8.1 µs |
| typed match, 200-route bucket (last candidate) | 740 µs | 12.7 µs |
| static match | 1.6 µs | 1.8 µs |
| 404 | 17.6 µs | 2.3 µs |
| 405 / `getAllowedMethods()` | 197 µs | 8.5 µs |
| `allowedMethods()` on an unknown path (guarded) | — | 3.7 µs |
| compile a 300-bucket table on first match | 32 µs | 46 µs |

The last row shows the trade-off: the first match pays to compile the buckets it needs.
Buckets are compiled on demand, so the cost depends on the routes a request looks at,
not the entire table. An earlier revision compiled every bucket eagerly and took 2.8 ms
for the same table.

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
