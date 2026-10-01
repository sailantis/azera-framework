# Architecture

**How Azera fits together** – from the `AppContext` service container to the MVC layer, the database abstraction, and the CLI tools.

![Azera Overall Architecture](images/architecture-overview.svg)

## Core Principles

- Lightweight runtime with minimal mandatory dependencies
- Explicit routing and dispatch
- Unified query API for model and table workflows
- Read/write DB separation support
- Simple composition through `AppContext`

## Main Components

### `AppContext`

Central runtime context and service container – accessed as a singleton via `AppContext::instance()`.

Built-in lazy service accessors:

| Method        | Returns                                                                           |
| ------------- | --------------------------------------------------------------------------------- |
| `request()`   | `Azera\Http\Request`                                                              |
| `view()`      | `Azera\Core\ViewEngine`                                                           |
| `session()`   | `Azera\Http\Session\|null`                                                        |
| `cookies()`   | `Azera\Http\Cookies`                                                              |
| `dbManager()` | `Azera\Db\DatabaseManager`                                                        |
| `route()`     | `Azera\Core\ResolvedRoute\|null` – populated by Dispatcher                        |
| `logger()`    | `Psr\Log\LoggerInterface` – `NullLogger` by default                               |
| `events()`    | `Psr\EventDispatcher\EventDispatcherInterface` – `NullEventDispatcher` by default |
| `cache()`     | `Psr\SimpleCache\CacheInterface` – `NullCache` by default                         |
| `queue()`     | `Azera\Queue\QueueInterface` – throws if unregistered                             |
| `config()`    | `Azera\Config\Config` – lazily created                                            |

Custom services can be registered with `$ctx->set($id, new MyService())` or `$ctx->set($id, fn() => new MyService())` and retrieved with `$ctx->get($id)`. Registered callables are treated as zero-argument lazy factories and cached on first resolution. Auto-wiring is supported: unregistered class names are instantiated via reflection with their constructor dependencies resolved recursively from the container.

### MVC Layer

- `Router` matches URI + method to route patterns, extracting typed parameters; supports named routes, inline or scoped groups (`prefix()`, `namespace()`, `controller()`, `middleware()`), and custom parameter validators
- `Dispatcher` is instantiated without arguments; obtains `AppContext` internally. It resolves controllers via DI (`AppContext::get()`), runs the global and per-route middleware pipeline, injects action parameters (route vars or DI), and stores resolved route info via `AppContext::setRoute()`
- `Controller` provides access to request/context plus controller- and action-level middleware declarations
- `ViewEngine` renders templates, layouts, and namespaced views; supports global view variables and partial rendering
- `ModelMapping` maps logical model names to PHP classes and table names – useful when decoupling route/query naming from class names
- `MiddlewareInterface` defines the contract for all middleware; `SessionMiddleware` is the built-in implementation
- `ResolvedRoute` (in `AppContext->route()`) contains resolved route information accessible anywhere

### Data Layer

- `Model` (Azera\Orm) provides Active Record style methods (`find`, `findAll`, `create`, `save`, `upsert`, `delete`, …) delegating to the `EntityManager` — identity-mapped reads, diff-based writes, heap-backed state tracking (`hasChanged`/`loadState`)
- `Query` is the fluent SQL builder for select, write, count, and exists operations; terminal calls (`insert`, `upsert`, `update`, `delete`) finalize the query
- `Database` wraps PDO with transaction helpers and lazy connection creation
- `ResultSet` provides iterable, countable access to model or raw-row results
- `Paginator` wraps a `Query` builder to add page/offset/total metadata with minimal boilerplate
- `Condition`, `Sql`, and `SqlCase` provide composable SQL fragments and safe escape hatches (`Sql::bind()` for PDO-bound values)

### CLI Layer

- `Console` maps CLI arguments to `Task` classes and `*Action()` methods; additional namespaces can be registered for discovery
- `Task` is the base class for all commands
- `ModelSyncTask` is the built-in task that drives model synchronisation (see Sync Layer below)

## Request Flow (Web)

```text
HTTP Request
  -> Router::match()
  -> Dispatcher::dispatch(routeInfo)
  -> Controller action
  -> Response
```

`Dispatcher` maps controller return types automatically:

- `Response` -> sent as-is
- `array` / `JsonSerializable` -> JSON response
- `string` -> text response
- `int` -> status response
- `null` -> `204`

## Data Flow

Two entry points into the database: models for object-oriented work, `Query` for direct table access.

```php
// Model-centric
$user = User::find(10);
$rows = User::query()->where('status', 'active')->select();

// Table-centric
$rows = Azera\Db\Query::raw()
    ->table('users')
    ->where('status', 'active')
    ->select();
```

Write operations are terminal builder calls (`insert`, `upsert`, `update`, `delete`).

## Read/Write Separation

Azera supports replica setups through `DatabaseManager` roles.

Register named connections in bootstrap:

```php
$mgr = $ctx->dbManager();
$mgr->set('write', new Database('mysql:host=primary;dbname=app', 'rw', 'secret'));
$mgr->set('read',  fn() => new Database('mysql:host=replica;dbname=app', 'ro', 'secret'));
```

Models read from the `read` role and write to the `write` role by default, falling back to the registered default when the role is absent. Per-model overrides:

```php
User::setDefaultReadRole('replica');
User::setDefaultWriteRole('primary');
User::setDefaultRole('analytics'); // both read + write
```

For single-database apps, register one connection under any name – models fall through to the default automatically:

```php
$ctx->dbManager()->set('default', new Database(...));
```

## Enterprise Subsystems

Azera provides opt-in enterprise subsystems that use PSR interfaces directly (no adapters needed):

| Subsystem | PSR    | Accessor   | Default                |
| --------- | ------ | ---------- | ---------------------- |
| Logging   | PSR-3  | `logger()` | `NullLogger`           |
| Events    | PSR-14 | `events()` | `NullEventDispatcher`  |
| Cache     | PSR-16 | `cache()`  | `NullCache`            |
| Queue     | —      | `queue()`  | throws if unregistered |
| Config    | —      | `config()` | empty `Config`         |

All subsystems are **zero-cost when unused** — each accessor lazily returns a no-op default, so calling code can always invoke `$ctx->logger()->info(...)` or `$ctx->events()->dispatch(...)` without null-checks. Register real implementations via `$ctx->set(InterfaceClass::class, $factory)` to activate them.

### AOP (Aspect-Oriented Programming)

Azera supports declarative cross-cutting concerns via PHP 8 attributes:

- `#[Advised]` marks a class for proxy generation
- `#[Transactional]` wraps methods in DB transactions
- `#[Cache]` caches method return values
- `#[Retry]` retries on failure with backoff
- `#[Log]` logs method entry/exit/duration

The `ProxyFactory` generates file-based proxy classes (OPcache'd) that extend the target and wrap advised methods. Services must be autowired (class string, not factory) for proxy generation. See [AOP](16-AOP.md).

### Security

- `CsrfMiddleware` — synchronizer token pattern, implements `MiddlewareInterface`
- `RateLimiter` — cache-backed, fixed-window rate limiting
- `Hasher` — password hashing (PHP `password_hash`/`password_verify`)
- `AuthManagerInterface` / `GuardInterface` — authentication contracts

See [Security](18-SECURITY-ENTERPRISE.md).

## Sync Layer

Azera ships a code-generation and schema-synchronisation subsystem under `Azera\Sync`. It introspects a live database and updates (or generates) Model PHP files to match the schema, without requiring a separate migration runner.

Key components:

- `SyncRunner` – orchestrates the sync: reads schema, diffs against the parsed model, applies or previews changes
- `ModelParser` – parses an existing PHP model file into a `ParsedModel` with typed properties and accessors
- `ModelDiff` – computes the diff between the live schema and the parsed model (add/remove/change columns and accessors)
- `CodeGenerator` – writes the updated PHP source back to the model file
- `SyncOptions` / `SyncResult` – configure dry-run/preview mode and carry the result back to the caller
- `Schema\` – driver-specific providers (`MySqlSchemaProvider`, `PostgresSchemaProvider`, `SqliteSchemaProvider`) that return a normalised `TableSchema`

The CLI entry point is the built-in `ModelSyncTask`; the same `SyncRunner` API can be called programmatically.

## Extensibility Points

- Router custom parameter validators via `type()`
- Router route groups via inline or scoped `prefix()`, `namespace()`, `controller()`, and `middleware()`
- Dispatcher middleware groups and controller factory
- Model overrides: `source()`, `schema()`, `idFields()`, `readConnection()`, `writeConnection()`
- Query SQL escape hatches via `Sql` nodes (`Sql::bind()` for PDO-bound params)
- PSR-3 logger: register any `Psr\Log\LoggerInterface` implementation via `$ctx->set()`
- PSR-14 events: register any `Psr\EventDispatcher\EventDispatcherInterface`, listen to typed events
- PSR-16 cache: register any `Psr\SimpleCache\CacheInterface` implementation
- Queue: register any `Azera\Queue\QueueInterface` implementation (SyncQueue or async driver)
- AOP interceptors: `registerInterceptor(AdviceClass::class, $interceptor)` for custom advice attributes
- Security: `CsrfMiddleware`, `RateLimiter`, `Hasher`, custom `GuardInterface` implementations
