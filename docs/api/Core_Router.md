# Class: Router

**Full name:** [Azera\Core\Router](../../src/Core/Router.php)

A simple and efficient router for mapping HTTP requests to handlers based on URI patterns and HTTP methods. Supports static routes, typed parameters, optional segments, wildcards, and route groups with shared middleware, namespaces, or controllers. Routes are matched in order of specificity, with static routes taking precedence over dynamic ones. Named routes allow for easy URL generation.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Router.php#L43)</small>

`public function __construct(): mixed`

Create a new Router instance.

**Return value**

- Type: `mixed`


---

### type() · <small>[🗎](../../src/Core/Router.php#L75)</small>

`public function type(string $name, callable $validator): static`

Register a custom type validator for route parameters.

Predefined types include 'int', 'alpha', 'alnum', 'uuid', and '*' (matches anything). You can add your own types with custom validation logic. For example, you could add a 'slug' type that only allows lowercase letters, numbers, and hyphens. Once a type is registered, you can use it in your route patterns like /blog/{slug:slug}.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The type name (e.g., 'slug', 'email') |
| `$validator` | callable | - | Function that validates a string value, returns bool |

**Return value**

- Type: `static`
- Description: For method chaining

**Example**

```php
$router->type('slug', fn($v) => preg_match('/^[a-z0-9-]+$/', $v));
$router->add('GET', '/blog/{slug:slug}', 'Blog::view');
```


---

### autoOptions() · <small>[🗎](../../src/Core/Router.php#L111)</small>

`public function autoOptions(bool $enabled = true): static`

Enable or disable automatic OPTIONS responses.

When enabled, if an OPTIONS request is received for a path that has
routes registered for other HTTP methods (GET, POST, etc.) but no
explicit OPTIONS route, the router will return a synthetic match
that the Dispatcher converts into a 204 response with Allow and
CORS headers.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$enabled` | bool | `true` | Whether to enable auto-OPTIONS handling |

**Return value**

- Type: `static`
- Description: For method chaining


---

### getAllowedMethods() · <small>[🗎](../../src/Core/Router.php#L124)</small>

`public function getAllowedMethods(string $uri): array`

Get all HTTP methods that have routes matching the given URI.

Useful for generating Allow headers for OPTIONS requests or 405 responses.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$uri` | string | - | The request URI (path) to check, e.g. "/blog/hello-world" |

**Return value**

- Type: `array`
- Description: Array of HTTP methods (e.g., ['GET', 'POST'])


---

### add() · <small>[🗎](../../src/Core/Router.php#L146)</small>

`public function add(array|string|null $method, string $pattern, array|string|null $handler = null): static`

Add a new route to the router. The route can be defined for specific HTTP methods, a URI pattern, and an optional handler that overrides the default controller/action resolution. The pattern can include static segments, typed parameters, dynamic segments for namespace/controller/action, and wildcard segments for additional parameters. Validators can be applied to dynamic parameters using predefined or custom types. For example: /user/{id:int} or /blog/{slug:slug}

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$method` | array\|string\|null | - | HTTP method(s) for the route (e.g., 'GET', ['GET', 'POST'], or '*' for all methods) |
| `$pattern` | string | - | Route pattern (e.g., '/blog/{slug}', '/{controller}/{action}/{params:*}') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action. Can be a string like 'Admin::dashboard' or an array of strings with keys 'namespace', 'controller', 'action'. |

**Return value**

- Type: `static`
- Description: For method chaining


---

### get() · <small>[🗎](../../src/Core/Router.php#L268)</small>

`public function get(string $pattern, array|string|null $handler = null): static`

Convenience method to add a GET route.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - | Route pattern (e.g., '/blog/{slug}') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action |

**Return value**

- Type: `static`
- Description: For method chaining


---

### post() · <small>[🗎](../../src/Core/Router.php#L280)</small>

`public function post(string $pattern, array|string|null $handler = null): static`

Convenience method to add a POST route.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - | Route pattern (e.g., '/submit') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action |

**Return value**

- Type: `static`
- Description: For method chaining


---

### put() · <small>[🗎](../../src/Core/Router.php#L292)</small>

`public function put(string $pattern, array|string|null $handler = null): static`

Convenience method to add a PUT route.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - | Route pattern (e.g., '/submit') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action |

**Return value**

- Type: `static`
- Description: For method chaining


---

### delete() · <small>[🗎](../../src/Core/Router.php#L304)</small>

`public function delete(string $pattern, array|string|null $handler = null): static`

Convenience method to add a DELETE route.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - | Route pattern (e.g., '/submit') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action |

**Return value**

- Type: `static`
- Description: For method chaining


---

### patch() · <small>[🗎](../../src/Core/Router.php#L316)</small>

`public function patch(string $pattern, array|string|null $handler = null): static`

Convenience method to add a PATCH route.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - | Route pattern (e.g., '/submit') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action |

**Return value**

- Type: `static`
- Description: For method chaining


---

### setName() · <small>[🗎](../../src/Core/Router.php#L328)</small>

`public function setName(string $name): static`

Assign a name to the most recently added route. This allows you to generate URLs for this route using the `urlFor()` method.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name to assign to the route |

**Return value**

- Type: `static`
- Description: For method chaining

**Throws**

- LogicException  If no route has been added yet or if the last added route is invalid


---

### hasNamedRoute() · <small>[🗎](../../src/Core/Router.php#L349)</small>

`public function hasNamedRoute(string $name): bool`

Check if a named route exists.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the route to check |

**Return value**

- Type: `bool`
- Description: True if a route with the given name exists, false otherwise


---

### urlFor() · <small>[🗎](../../src/Core/Router.php#L365)</small>

`public function urlFor(string $name, array $params = [], array $query = []): string`

Generate a URL for a named route, substituting parameters as needed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The name of the route to generate a URL for |
| `$params` | array | `[]` | Associative array of parameter values to substitute into the route pattern |
| `$query` | array | `[]` | Optional associative array of query parameters to append to the URL |

**Return value**

- Type: `string`
- Description: The generated URL path (e.g., "/blog/hello-world?ref=homepage")

**Throws**

- RuntimeException  If no route with the given name exists or if required parameters are missing/invalid


---

### allRoutes() · <small>[🗎](../../src/Core/Router.php#L394)</small>

`public function allRoutes(): array`

Return all registered routes as a flat array.

Each entry is an associative array with keys:
- 'method':    HTTP method (GET, POST, …)
- 'pattern':   Reconstructed path pattern (e.g. "/users/{id:int}")
- 'handler':   The handler value (string, array, or null)
- 'groups':    Middleware group names applied to this route
- 'name':      Route name if one was assigned, or null

**Return value**

- Type: `array`


---

### prefix() · <small>[🗎](../../src/Core/Router.php#L556)</small>

`public function prefix(string $prefix, callable|null $callback = null): static`

Define a group of routes that share a common URI prefix. When a callback is supplied, the prefix is scoped to that callback and the router restores the previous group state afterward. When omitted, the prefix stays on the stack for subsequent routes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$prefix` | string | - | URI prefix for the group (e.g., "/admin") |
| `$callback` | callable\|null | `null` | Optional callback that receives the router instance to define routes within the group |

**Return value**

- Type: `static`
- Description: For method chaining

**Example**

```php
$router->prefix('/admin');
$router->add('GET', '/dashboard', 'Admin::dashboard');
```


---

### middleware() · <small>[🗎](../../src/Core/Router.php#L590)</small>

`public function middleware(array|string $name, callable|null $callback = null): static`

Add group of middleware to be applied to all routes defined within the group. When a callback is supplied, the middleware groups are scoped to that callback and the router restores the previous stack afterward. When omitted, the middleware stays on the active stack for subsequent routes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | array\|string | - | Middleware group name (e.g., "auth") |
| `$callback` | callable\|null | `null` | Optional callback that receives the router instance to define routes within the group |

**Return value**

- Type: `static`
- Description: For method chaining

**Example**

```php
$router->middleware('auth');
$router->add('GET', '/admin/dashboard', 'Admin::dashboard');
```


---

### namespace() · <small>[🗎](../../src/Core/Router.php#L626)</small>

`public function namespace(string $namespace, callable|null $callback = null): static`

Define a group of routes that share a common namespace for their handlers. When a callback is supplied, the namespace is scoped to that callback and the router restores the previous group state afterward. When omitted, the namespace stays on the stack for subsequent routes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - | Namespace prefix for the group (e.g., "Admin") |
| `$callback` | callable\|null | `null` | Optional callback that receives the router instance to define routes within the group |

**Return value**

- Type: `static`
- Description: For method chaining

**Example**

```php
$router->namespace('Admin');
$router->add('GET', '/dashboard', 'Dashboard::view');
```


---

### controller() · <small>[🗎](../../src/Core/Router.php#L668)</small>

`public function controller(string $controller, callable|null $callback = null): static`

Define a group of routes that share a common controller. When a callback is supplied, the controller is scoped to that callback and the router restores the previous group state afterward. When omitted, the controller stays on the stack for subsequent routes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$controller` | string | - | Controller name for the group (e.g., "Admin") |
| `$callback` | callable\|null | `null` | Optional callback that receives the router instance to define routes within the group |

**Return value**

- Type: `static`
- Description: For method chaining

**Example**

```php
$router->controller('Admin');
$router->add('GET', '/dashboard', '::view');
```


---

### match() · <small>[🗎](../../src/Core/Router.php#L940)</small>

`public function match(string $uri, string $method = 'GET'): array|null`

Attempt to match the given URI and HTTP method against the registered routes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$uri` | string | - | The request URI (path) to match, e.g. "/blog/hello-world" |
| `$method` | string | `'GET'` | The HTTP method, e.g. "GET", "POST" |

**Return value**

- Type: `array`|`null`
- Description: If a match is found, returns an array with keys 'vars', 'override', 'groups', 'wildcards'. Otherwise, returns null.


---

### exportState() · <small>[🗎](../../src/Core/Router.php#L1292)</small>

`public function exportState(): array`

Everything a compiler may persist, as a plain array of data.

The contract this rests on: a route is a *description* of how to find a
handler, so the table holds only strings, ints, bools, nulls and arrays of
those. `self::add()` enforces that for handlers and
`self::pushGroupValues()` for middleware, which is what lets this method
return the state without walking it looking for objects.

Two properties are deliberately excluded:

  `types`       the parameter validators, which are closures seeded by the
                constructor. They are code, not data. A compiler must not
                store them; a restored router gets them from `__construct()`
                automatically. An application that registered its own with
                `self::type()` has to re-register it, and this method
                reports those names via `self::customTypes()` so the
                generated file can say so.

  `resolvedOverrides`
                a per-handler memo of the parse that produces `override`. It
                is derived purely from the handlers already in the table, so
                persisting it would duplicate data, grow the file, and risk
                being stale after an edit to a handler.

Note that the *deferred sort* state (`unsortedBuckets`) IS included. It is
data, and restoring it faithfully is what keeps a restored router
behaviourally identical to a freshly built one rather than accidentally
faster because a stale sort was skipped.

**Return value**

- Type: `array`


---

### restoreState() · <small>[🗎](../../src/Core/Router.php#L1324)</small>

`public function restoreState(array $state): static`

Replace this router's table with a previously exported one.

Only the keys `self::exportState()` produces are applied, so a file
written by an older release cannot reintroduce a property that has since been
removed, and cannot overwrite `types`. Unknown keys are ignored rather than
assigned, which keeps the method safe against a hand-edited cache file.

This does not clear the table first: it overwrites the keys it knows, so
restoring onto a router that already has routes replaces its routes for those
buckets. Call `self::forget()` first if that matters -- the CLI task and
every benchmark entry point do.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$state` | array | - |  |

**Return value**

- Type: `static`


---

### customTypes() · <small>[🗎](../../src/Core/Router.php#L1365)</small>

`public function customTypes(): array`

The parameter type names this router knows that the constructor does not.

These are the ones registered with `self::type()`. Their validators are
closures and therefore cannot be cached, so an application using them must
re-register them after restoring a compiled table -- exactly as it registered
them when building the table in the first place. The `routes:cache` command
writes this list into the generated file's header so the requirement is
visible where it matters.

**Return value**

- Type: `array`


---

### enableCache() · <small>[🗎](../../src/Core/Router.php#L1426)</small>

`public function enableCache(string $path): static`

Turn the route cache on and say where it lives.

Must be called before any route is registered: a cache needs every route to
be attributable to a file, and a route registered beforehand has no file.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path of the compiled table. Its directory is<br>created on the first write. |

**Return value**

- Type: `static`


---

### enableCacheLazy() · <small>[🗎](../../src/Core/Router.php#L1439)</small>

`public function enableCacheLazy(string $path): static`

Enable the cache but never write from a request path.

The table is still read and restored; only the build-on-miss write is skipped.
Useful when the code directory is read-only and warming is a deploy-step job
(`azera routes:cache`), and it removes the one behaviour a request path can
otherwise trigger -- creating and replacing a file.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**Return value**

- Type: `static`


---

### setRevalidateFreq() · <small>[🗎](../../src/Core/Router.php#L1484)</small>

`public function setRevalidateFreq(int $seconds): static`

How many seconds a positive freshness verdict may be reused for.

The route-table analogue of `opcache.revalidate_freq` and of
`Clarity\Engine\Cache::setRevalidateFreq()`, with one deliberate difference.
Clarity must re-check per *render*, because one template renders many times in
a request. A router is read once per request, so the meaningful unit here is
the process: under php-fpm, where a process serves one request, this setting
changes nothing. In a long-lived worker (Volucer, RoadRunner) the router
outlives the request, and a positive value bounds how long a rebuilt table may
go unnoticed.

Only *positive* verdicts are reused. A negative one means the next rebuild must
happen and must see the current filesystem.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$seconds` | int | - | Seconds; negative values are treated as 0. |

**Return value**

- Type: `static`


---

### getRevalidateFreq() · <small>[🗎](../../src/Core/Router.php#L1496)</small>

`public function getRevalidateFreq(): int`

How many seconds a positive freshness verdict is reused for.

**Return value**

- Type: `int`


---

### cachePath() · <small>[🗎](../../src/Core/Router.php#L1504)</small>

`public function cachePath(): string|null`

Where the compiled table lives, or null when caching is off.

**Return value**

- Type: `string`|`null`


---

### cached() · <small>[🗎](../../src/Core/Router.php#L1552)</small>

`public function cached(array|string $files, callable $build): static`

Declare a block of routes and the files it comes from.

$router->cached(__FILE__, function (Router $r): void {
        $r->get('/', 'IndexController::indexAction');
    });

The block does not run here. It runs at the first read of the router
(`match()`, `allRoutes()`, `urlFor()`, `hasNamedRoute()`,
`getAllowedMethods()`) -- and only if the compiled table is missing or stale.
That ordering is the whole design: deciding whether to rebuild requires reading
a file, and running the block first would pay the cost the cache exists to
remove.

`$files` is normally `__FILE__`. Using the constant rather than inferring the
file from a backtrace is deliberate: `__FILE__` is resolved at compile time, so
it names the file the block was *written* in no matter how many helpers sit
between the block and `add()`. A backtrace records whichever frame happens to be
nearest, which is silently plausible and wrong as soon as anything wraps the
call.

Several files are allowed for one block; the cache is then watched against all
of them. Several blocks are allowed too -- they execute in declaration order,
which is what keeps specificity ties resolving the same way a cold build did.

Rules, enforced rather than documented:

  NO NESTING. A unit is the unit; an inner one would lose nothing and gain
  nothing, so it is refused instead of half-supported.

  TOP LEVEL ONLY. `cached()` inside a prefix/middleware/namespace/controller
  callback would have to capture that stack to replay it later; the stack is
  state on the router, so deferring the body would silently register the routes
  at the top level. Refused.

  NO ROUTES OUTSIDE A UNIT. Enforced in `self::add()`, which is the only
  place a route enters the table.

With no cache enabled there is nothing to decide, so the block simply runs.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$files` | array\|string | - | Declared source files, conventionally `__FILE__`. |
| `$build` | callable | - | Receives this router. |

**Return value**

- Type: `static`


---

### cachedSources() · <small>[🗎](../../src/Core/Router.php#L1622)</small>

`public function cachedSources(): array`

The declared source files of every unit, deduplicated, in declaration order.

**Return value**

- Type: `array`


---

### warnOnWriteFailure() · <small>[🗎](../../src/Core/Router.php#L1859)</small>

`public function warnOnWriteFailure(bool $on = true): static`

Report a failed background write instead of failing silently.

Off by default: a container whose cache directory is mounted read-only is a
legitimate configuration, and the only cost is rebuilding per request.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$on` | bool | `true` |  |

**Return value**

- Type: `static`


---

### compileCache() · <small>[🗎](../../src/Core/Router.php#L1973)</small>

`public function compileCache(): int`

Force a rebuild from the declared units and write it, ignoring the file.

This is what `azera routes:cache` calls. It exists for ops -- a deploy hook
that wants the table warm before the first request, or a rebuild after deleting
the file. Nothing depends on it: a missing file is written by the first request
that reads the router.

**Return value**

- Type: `int`
- Description: Number of routes written.


---

### clearCache() · <small>[🗎](../../src/Core/Router.php#L1999)</small>

`public function clearCache(): bool`

Delete the compiled table.

**Return value**

- Type: `bool`
- Description: True when a file was removed.


---

### cacheStatus() · <small>[🗎](../../src/Core/Router.php#L2023)</small>

`public function cacheStatus(): array`

Describe the compiled table without trusting it.

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
