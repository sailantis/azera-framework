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

### get() · <small>[🗎](../../src/Core/Router.php#L265)</small>

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

### post() · <small>[🗎](../../src/Core/Router.php#L277)</small>

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

### put() · <small>[🗎](../../src/Core/Router.php#L289)</small>

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

### delete() · <small>[🗎](../../src/Core/Router.php#L301)</small>

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

### patch() · <small>[🗎](../../src/Core/Router.php#L313)</small>

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

### setName() · <small>[🗎](../../src/Core/Router.php#L325)</small>

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

### hasNamedRoute() · <small>[🗎](../../src/Core/Router.php#L346)</small>

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

### urlFor() · <small>[🗎](../../src/Core/Router.php#L362)</small>

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

### allRoutes() · <small>[🗎](../../src/Core/Router.php#L391)</small>

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

### prefix() · <small>[🗎](../../src/Core/Router.php#L553)</small>

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

### middleware() · <small>[🗎](../../src/Core/Router.php#L587)</small>

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

### namespace() · <small>[🗎](../../src/Core/Router.php#L623)</small>

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

### controller() · <small>[🗎](../../src/Core/Router.php#L665)</small>

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

### match() · <small>[🗎](../../src/Core/Router.php#L937)</small>

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

### exportState() · <small>[🗎](../../src/Core/Router.php#L1249)</small>

`public function exportState(): array`

Return the serializable route table: scalars and arrays only. Handlers and
middleware values are validated by `self::add()` and
`self::pushGroupValues()`.

`types` is omitted because validators are closures; built-ins are restored by
the constructor, and custom types must be re-registered (`customTypes()` lists
them). `resolvedOverrides` is derived from handlers and is not persisted.
`unsortedBuckets` is included to preserve deferred sorting behavior.

**Return value**

- Type: `array`


---

### restoreState() · <small>[🗎](../../src/Core/Router.php#L1281)</small>

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

### customTypes() · <small>[🗎](../../src/Core/Router.php#L1322)</small>

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

### setCachePath() · <small>[🗎](../../src/Core/Router.php#L1380)</small>

`public function setCachePath(string|null $path): static`

Set the compiled table path before the router is first read.

`self::cached()` enables caching automatically. Pass null to use the
derived default path; use `self::disableCache()` to opt out.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string\|null | - | Absolute file path, or null for the derived default.<br>The directory is created on write. |

**Return value**

- Type: `static`


---

### disableCache() · <small>[🗎](../../src/Core/Router.php#L1413)</small>

`public function disableCache(): static`

Disable compiled-table caching.

Pending route blocks are registered immediately; an already-loaded table stays
in memory, but the compiled file is ignored for this process. Useful for
uncached benchmarks when bootstrap always declares `cached()` units.

**Return value**

- Type: `static`


---

### cachePath() · <small>[🗎](../../src/Core/Router.php#L1456)</small>

`public function cachePath(): string|null`

Return the compiled table path, or null when caching is disabled or no sources
are declared. An explicit `self::setCachePath()` takes precedence;
otherwise the path is derived from the source files under
`sys_get_temp_dir()/azera_routes/`. The source hash isolates applications and
route-file sets; when available, the user ID isolates files in the 0700 directory.

The derived path is available only after route units are declared.

**Return value**

- Type: `string`|`null`


---

### resolvedCachePath() · <small>[🗎](../../src/Core/Router.php#L1492)</small>

`public function resolvedCachePath(): string|null`

The path the table would be written to, or null when no unit is declared.

Kept separate from `self::cachePath()` so diagnostics never resolve and
cache a name that the application has not actually opted into.

**Return value**

- Type: `string`|`null`


---

### setRevalidateFreq() · <small>[🗎](../../src/Core/Router.php#L1507)</small>

`public function setRevalidateFreq(int $seconds): static`

Seconds to reuse a positive route-table freshness verdict.

Unlike template caching, the router checks once per request, not per render.
This matters only when a Router instance survives across requests; a positive
value can delay detecting source changes by that interval. Negative verdicts
are never reused, so the next rebuild checks the current files.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$seconds` | int | - | Seconds; negative values are treated as 0. |

**Return value**

- Type: `static`


---

### getRevalidateFreq() · <small>[🗎](../../src/Core/Router.php#L1519)</small>

`public function getRevalidateFreq(): int`

How many seconds a positive freshness verdict is reused for.

**Return value**

- Type: `int`


---

### cached() · <small>[🗎](../../src/Core/Router.php#L1543)</small>

`public function cached(array|string $files, callable $build): static`

Register a route block and its source file(s).

$router->cached(__FILE__, function (Router $r): void {
        $r->get('/', 'IndexController::indexAction');
    });

The callback runs on the router's first read, and only if the compiled table is
missing or stale. Pass explicit source files (usually `__FILE__`) so changes to
the declaring file invalidate the cache; multiple files are supported.

With caching enabled, blocks run in declaration order and must be top-level,
non-nested, and contain all routes; these restrictions are enforced. With caching
disabled, callbacks run immediately. Declaring a block enables caching;
`self::disableCache()` opts out.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$files` | array\|string | - | Declared source files, conventionally `__FILE__`. |
| `$build` | callable | - | Receives this router. |

**Return value**

- Type: `static`


---

### cachedSources() · <small>[🗎](../../src/Core/Router.php#L1613)</small>

`public function cachedSources(): array`

The declared source files of every unit, deduplicated, in declaration order.

**Return value**

- Type: `array`


---

### warnOnWriteFailure() · <small>[🗎](../../src/Core/Router.php#L1925)</small>

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

### compileCache() · <small>[🗎](../../src/Core/Router.php#L2081)</small>

`public function compileCache(): int`

Rebuild the table from declared units and write it, ignoring any existing file.

Used by `azera routes:cache` to prewarm or rebuild during deployment; otherwise
the first router read creates a missing table.

**Return value**

- Type: `int`
- Description: Number of routes written.


---

### clearCache() · <small>[🗎](../../src/Core/Router.php#L2107)</small>

`public function clearCache(): bool`

Delete the compiled table.

**Return value**

- Type: `bool`
- Description: True when a file was removed.


---

### cacheStatus() · <small>[🗎](../../src/Core/Router.php#L2131)</small>

`public function cacheStatus(): array`

Describe the compiled table without trusting it.

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
