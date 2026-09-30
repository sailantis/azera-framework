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

### autoOptions() · <small>[🗎](../../src/Core/Router.php#L92)</small>

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

### getAllowedMethods() · <small>[🗎](../../src/Core/Router.php#L105)</small>

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

### add() · <small>[🗎](../../src/Core/Router.php#L125)</small>

`public function add(array|string|null $method, string $pattern, array|string|null $handler = null): static`

Add a new route to the router. The route can be defined for specific HTTP methods, a URI pattern, and an optional handler that overrides the default controller/action resolution. The pattern can include static segments, typed parameters, dynamic segments for namespace/controller/action, and wildcard segments for additional parameters. Validators can be applied to dynamic parameters using predefined or custom types. For example: /user/{id:int} or /blog/{slug:slug}

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$method` | array\|string\|null | - | HTTP method(s) for the route (e.g., 'GET', ['GET', 'POST'], or '*' for all methods) |
| `$pattern` | string | - | Route pattern (e.g., '/blog/{slug}', '/{controller}/{action}/{params:*}') |
| `$handler` | array\|string\|null | `null` | Optional handler definition to override controller/action. Can be a string like 'Admin::dashboard' or an array with keys 'namespace', 'controller', 'action'. |

**Return value**

- Type: `static`
- Description: For method chaining


---

### get() · <small>[🗎](../../src/Core/Router.php#L211)</small>

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

### post() · <small>[🗎](../../src/Core/Router.php#L223)</small>

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

### put() · <small>[🗎](../../src/Core/Router.php#L235)</small>

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

### delete() · <small>[🗎](../../src/Core/Router.php#L247)</small>

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

### patch() · <small>[🗎](../../src/Core/Router.php#L259)</small>

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

### setName() · <small>[🗎](../../src/Core/Router.php#L271)</small>

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

### hasNamedRoute() · <small>[🗎](../../src/Core/Router.php#L290)</small>

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

### urlFor() · <small>[🗎](../../src/Core/Router.php#L304)</small>

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

### allRoutes() · <small>[🗎](../../src/Core/Router.php#L331)</small>

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

### prefix() · <small>[🗎](../../src/Core/Router.php#L473)</small>

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

### middleware() · <small>[🗎](../../src/Core/Router.php#L507)</small>

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

### namespace() · <small>[🗎](../../src/Core/Router.php#L543)</small>

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

### controller() · <small>[🗎](../../src/Core/Router.php#L585)</small>

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

### match() · <small>[🗎](../../src/Core/Router.php#L857)</small>

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

[Back to the Index ⤴](README.md)
