# Class: AppContext

**Full name:** [Azera\AppContext](../../src/AppContext.php)

Application context and dependency injection container.

This class is a singleton that manages the application's services, including
request-scoped services, critical services, and the service container.

## Public methods

### __construct() · <small>[🗎](../../src/AppContext.php#L41)</small>

`public function __construct(): mixed`

**Return value**

- Type: `mixed`


---

### instance() · <small>[🗎](../../src/AppContext.php#L84)</small>

`public static function instance(): static`

Get/create shared singleton instance

**Return value**

- Type: `static`


---

### setInstance() · <small>[🗎](../../src/AppContext.php#L93)</small>

`public static function setInstance(self $instance): void`

Set the shared singleton instance (e.g. for testing or multi-context scenarios).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$instance` | self | - |  |

**Return value**

- Type: `void`


---

### reset() · <small>[🗎](../../src/AppContext.php#L106)</small>

`public static function reset(): void`

Drop the shared singleton instance.

Call in test setUp()/tearDown() (or between multi-context scenarios) to
guarantee each test starts from a pristine context, instead of relying on
a previous test having called `setInstance()`. After this, the next
`instance()` call lazily builds a fresh context.

**Return value**

- Type: `void`


---

### request() · <small>[🗎](../../src/AppContext.php#L118)</small>

`public function request(): Azera\Http\Request`

Get the HttpRequest instance, resolved through the DI container.

**Return value**

- Type: [Request](Http_Request.md)
- Description: The HttpRequest instance.


---

### view() · <small>[🗎](../../src/AppContext.php#L139)</small>

`public function view(): Azera\Core\ViewEngine`

Get the active view engine instance.

Resolution is fully delegated to the DI container: the default definition
builds a ClarityEngine, an app may register its own factory via
`set(ViewEngine::class, $factory)` (deferred build, resolved on first
use) or an instance via `setView()` (e.g. test doubles). view() and
get(ViewEngine::class) always return the same instance — one resolution
path, memoized by the container.

Boot code must NOT call this method (or get(ViewEngine::class)) — that
is what triggers the engine build and its class autoload cost.

**Return value**

- Type: [ViewEngine](Core_ViewEngine.md)
- Description: The active view engine instance.


---

### setView() · <small>[🗎](../../src/AppContext.php#L157)</small>

`public function setView(Azera\Core\ViewEngine $engine): static`

Replace the active view engine instance (e.g. swap in a specific engine
or a test double at bootstrap). Sugar over set(): the instance is bound
as both the container definition and the memoized instance.

To register a deferred factory instead, use
`set(ViewEngine::class, $factory)` — registering a callable unsets the
cached instance, so the factory applies on the next resolution.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ViewEngine](Core_ViewEngine.md) | - | The engine to use from this point on. |

**Return value**

- Type: `static`


---

### cookies() · <small>[🗎](../../src/AppContext.php#L168)</small>

`public function cookies(): Azera\Http\Cookies`

Get the Cookies instance, resolved through the DI container.

**Return value**

- Type: [Cookies](Http_Cookies.md)
- Description: The Cookies instance.


---

### heap() · <small>[🗎](../../src/AppContext.php#L185)</small>

`public function heap(): Azera\Orm\Heap`

Get the request-scoped ORM Heap (identity map).

One heap per request: the same DB row read twice yields the same
entity object, and the EntityManager diffs against the node
snapshot instead of scanning every constructed instance. The
heap is created lazily, registered in the container, and wiped by
`clearRequestScope()` via its RequestScoped hook (non-negotiable
in persistent workers — a leaking heap would serve stale entities
across requests/tenants).

**Return value**

- Type: [Heap](Orm_Heap.md)


---

### entityManager() · <small>[🗎](../../src/AppContext.php#L199)</small>

`public function entityManager(): Azera\Orm\EntityManager`

Get the request-scoped EntityManager (identity map + write pipeline).

Shares the request's Heap with everything else — FastHydrator reads,
Model::save() and direct EM calls all see the same identity space.
persist() schedules, flush() executes in one transaction. Wiped by
`clearRequestScope()` like the heap.

**Return value**

- Type: [EntityManager](Orm_EntityManager.md)


---

### dbManager() · <small>[🗎](../../src/AppContext.php#L208)</small>

`public function dbManager(): Azera\Db\DatabaseManager`

Get the DatabaseManager instance, resolved through the DI container.

**Return value**

- Type: [DatabaseManager](Db_DatabaseManager.md)


---

### router() · <small>[🗎](../../src/AppContext.php#L219)</small>

`public function router(): Azera\Core\Router`

Get the Router instance, resolved through the DI container.

**Return value**

- Type: [Router](Core_Router.md)
- Description: The Router instance.


---

### dispatcher() · <small>[🗎](../../src/AppContext.php#L230)</small>

`public function dispatcher(): Azera\Core\Dispatcher`

Get the Dispatcher instance, resolved through the DI container.

**Return value**

- Type: [Dispatcher](Core_Dispatcher.md)
- Description: The Dispatcher instance.


---

### logger() · <small>[🗎](../../src/AppContext.php#L243)</small>

`public function logger(): Psr\Log\LoggerInterface`

Get the logger instance. Returns a [`NullLogger`](Log_NullLogger.md) if no logger
has been registered, so calling code can safely log without
null-checks. Register a real logger via `set(LoggerInterface::class, ...)`.

**Return value**

- Type: `Psr\Log\LoggerInterface`


---

### events() · <small>[🗎](../../src/AppContext.php#L256)</small>

`public function events(): Psr\EventDispatcher\EventDispatcherInterface`

Get the event dispatcher. Returns a [`NullEventDispatcher`](Event_NullEventDispatcher.md) if
none has been registered, so `dispatch()` is always safe. Register
a real dispatcher via `set(EventDispatcherInterface::class, ...)`.

**Return value**

- Type: `Psr\EventDispatcher\EventDispatcherInterface`


---

### cache() · <small>[🗎](../../src/AppContext.php#L269)</small>

`public function cache(): Psr\SimpleCache\CacheInterface`

Get the cache instance. Returns a [`NullCache`](Cache_NullCache.md) if none has been
registered (always reports a miss). Register a real cache via
`set(CacheInterface::class, ...)`.

**Return value**

- Type: `Psr\SimpleCache\CacheInterface`


---

### queue() · <small>[🗎](../../src/AppContext.php#L286)</small>

`public function queue(): Azera\Queue\QueueInterface`

Get the queue instance.

Unlike the other subsystems, the queue has no null implementation
because silently dropping jobs would be dangerous. If no queue is
registered, this throws a LogicException with an install hint.
Register a queue via `set(QueueInterface::class, ...)`.

**Return value**

- Type: [QueueInterface](Queue_QueueInterface.md)

**Throws**

- LogicException  If no queue is registered.


---

### config() · <small>[🗎](../../src/AppContext.php#L302)</small>

`public function config(): Azera\Config\Config`

Get the configuration service. Lazily creates a [`Config`](Config_Config.md)
if none has been registered.

**Return value**

- Type: [Config](Config_Config.md)


---

### pipeline() · <small>[🗎](../../src/AppContext.php#L326)</small>

`public function pipeline(array $interceptors = []): Azera\Aop\Pipeline`

Create a pipeline for explicit interceptor composition.

This is the no-proxy alternative to AOP attributes. It lets you
wrap any callable with interceptors without generating proxy
classes. The same interceptors that work with the proxy AOP
also work here.

Example:
```php
$result = $ctx->pipeline()
    ->through([new RetryInterceptor(3), new LogInterceptor($logger)])
    ->call(fn() => $service->chargeCard(100));
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | `[]` |  |

**Return value**

- Type: [Pipeline](Aop_Pipeline.md)


---

### registerInterceptor() · <small>[🗎](../../src/AppContext.php#L348)</small>

`public function registerInterceptor(string $adviceClass, Azera\Aop\InterceptorInterface|callable $interceptor): void`

Register an interceptor for a specific advice type.

Once at least one interceptor is registered, the DI container
will proxy classes marked with [`Advised`](Aop_Advised.md) that have methods
carrying the corresponding advice attribute.

The interceptor may also be passed as a zero-argument factory
(closure/invokable) returning an InterceptorInterface. Factories are
resolved exactly once — when the ProxyFactory is first built — so boot
can register lazy wiring (e.g. an interceptor depending on dbManager())
without constructing anything during bootstrap.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$adviceClass` | string | - | The advice attribute class. |
| `$interceptor` | [InterceptorInterface](Aop_InterceptorInterface.md)\|callable | - | The interceptor to handle it (or a factory returning one). |

**Return value**

- Type: `void`


---

### setAopCacheDir() · <small>[🗎](../../src/AppContext.php#L389)</small>

`public function setAopCacheDir(string|null $dir): void`

Set the AOP proxy cache directory.

Pass a path for file-based proxy generation (OPcache-cached, production).
Pass null to use eval() (development, no cache files).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string\|null | - |  |

**Return value**

- Type: `void`


---

### session() · <small>[🗎](../../src/AppContext.php#L448)</small>

`public function session(): Azera\Http\Session|null`

Get the Session instance, or null until `setSession()` binds one.

**Return value**

- Type: [Session](Http_Session.md)|`null`


---

### setSession() · <small>[🗎](../../src/AppContext.php#L459)</small>

`public function setSession(Azera\Http\Session $session): void`

Set the Session instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$session` | [Session](Http_Session.md) | - | The Session instance to set in the context. |

**Return value**

- Type: `void`


---

### route() · <small>[🗎](../../src/AppContext.php#L467)</small>

`public function route(): Azera\Core\ResolvedRoute|null`

Get the current resolved route information.

**Return value**

- Type: [ResolvedRoute](Core_ResolvedRoute.md)|`null`


---

### setRoute() · <small>[🗎](../../src/AppContext.php#L477)</small>

`public function setRoute(Azera\Core\ResolvedRoute $route): void`

Set the current resolved route information.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$route` | [ResolvedRoute](Core_ResolvedRoute.md) | - | The resolved route to set in the context. |

**Return value**

- Type: `void`


---

### clearRequestScope() · <small>[🗎](../../src/AppContext.php#L504)</small>

`public function clearRequestScope(): void`

Clear all request-scoped state on this context.

Under a persistent application server (RoadRunner, Swoole, FrankenPHP,
Octane, …) the AppContext survives across many requests. This method
resets the per-request services so the next request starts clean:

 - the request-scoped container entries (Request, Session, Cookies)
   are removed so their accessors resolve fresh instances on demand;
 - the current [`ResolvedRoute`](Core_ResolvedRoute.md) value is cleared;
 - every service registered on the container that implements
   [`RequestScoped`](Lifecycle_RequestScoped.md) has its [`RequestScoped::resetState()`](Lifecycle_RequestScoped.md#resetstate) hook
   called — this covers the ORM Heap and EntityManager, whose identity
   state is wiped in place while their instances stay registered
   (persistent-worker contract: handles survive, state dies).

Persistent infrastructure is deliberately left untouched — database
manager, cache/Redis backends, queue, logger and event dispatcher keep
their handles and connections alive across requests.

Safe to call repeatedly; a no-op when no request has been processed yet.

**Return value**

- Type: `void`


---

### set() · <small>[🗎](../../src/AppContext.php#L536)</small>

`public function set(string $id, callable|object|null $service = null): void`

Register a service instance or lazy factory in the context.

Registered callables are treated as zero-argument factories. They are invoked on
first resolution and their returned object is cached for subsequent lookups.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | The identifier for the service (usually the class name). |
| `$service` | callable\|object\|null | `null` | Optional service instance or zero-argument factory to register. |

**Return value**

- Type: `void`


---

### has() · <small>[🗎](../../src/AppContext.php#L554)</small>

`public function has(string $id): bool`

Check if a service is registered in the context.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | The identifier of the service to check. |

**Return value**

- Type: `bool`
- Description: True if the service is registered, false otherwise.


---

### get() · <small>[🗎](../../src/AppContext.php#L572)</small>

`public function get(string $id): object`

Get a service instance from the context.

If the service is registered as a callable, it will be invoked lazily
once and the returned object will be cached. If the service is not
registered but the identifier is a class name, it will attempt to
auto-wire and instantiate it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | The identifier of the service to retrieve. |

**Return value**

- Type: `object`
- Description: The service instance associated with the given identifier.

**Throws**

- RuntimeException  If the service is not found and cannot be auto-wired.


---

### tryGet() · <small>[🗎](../../src/AppContext.php#L601)</small>

`public function tryGet(string $id): object|null`

Try to get a service instance from the context.

If the service is registered as a callable, it will be invoked lazily
once and the returned object will be cached. If the service is not
registered but the identifier is a class name, it will attempt to
auto-wire and instantiate it. Returns null if the service is not found,
or if a registered factory currently resolves to null.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | The identifier of the service to retrieve. |

**Return value**

- Type: `object`|`null`
- Description: The service instance associated with the given identifier, or null if not found.


---

### getOrNull() · <small>[🗎](../../src/AppContext.php#L629)</small>

`public function getOrNull(string $id): object|null`

Get a registered service instance if it exists, or null if it does not.

Registered factories are resolved lazily. This method does not attempt
to auto-wire or instantiate classes that have not been registered
explicitly.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - | The identifier of the service to retrieve. |

**Return value**

- Type: `object`|`null`
- Description: The service instance associated with the given identifier, or null if not found.



---

[Back to the Index ⤴](README.md)
