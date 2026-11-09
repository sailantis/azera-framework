# MVC Routing

**Map URLs to controllers** – patterns, parameters, groups, named routes, and middleware.

`Azera\Core\Router` matches URI + HTTP method to controller/action metadata.
`Azera\Core\Dispatcher` executes that route and returns a `Response`.

## Basic Usage

```php
use Azera\AppContext;
use Azera\Core\Dispatcher;
use Azera\Core\Router;

$ctx = AppContext::instance();

$router = $ctx->router();
$router->add('GET', '/', 'IndexController::indexAction');
$router->add('GET', '/users/{id:int}', 'UserController::viewAction');

// Configure dispatcher
$dispatcher = new Dispatcher();
$dispatcher->setBaseNamespace('\\App\\Controllers');

$route = $router->match('/users/42', 'GET');
if ($route !== null) {
    $response = $dispatcher->dispatch($route);
    $response->send();
}
```

## Route Patterns

### Static

Exact matches, fastest to resolve:

```php
$router->add('GET', '/about', 'PageController::aboutAction');
```

### Named Parameters

Capture dynamic segments as parameters passed to your controller action:

```php
$router->add('GET', '/blog/{slug}', 'BlogController::showAction');
```

### Typed Parameters

Type constraints validate parameters before they reach your controller.

Built-in types:

| Type    | Accepts                            |
| ------- | ---------------------------------- |
| `int`   | Digits only (`ctype_digit`)        |
| `alpha` | Letters only (`ctype_alpha`)       |
| `alnum` | Letters and digits (`ctype_alnum`) |
| `uuid`  | 36-char UUID with hyphens          |
| (none)  | Any single segment (no validation) |

```php
$router->add('GET', '/users/{id:int}', 'UserController::viewAction');
$router->add('GET', '/tags/{name:alpha}', 'TagController::showAction');
$router->add('GET', '/items/{slug}', 'ItemController::showAction'); // any single segment
```

> **Note:** `{slug}` (no type) matches **one** URL segment with any value. `{files:*}` (explicit `*` type) is a **wildcard** that captures all remaining segments as an array — see [Wildcard Parameters](#wildcard-parameters) below.

#### Regex Type

Match a segment against a custom PCRE pattern (no delimiters). The pattern is matched against individual URL segments only, not across segment boundaries.

```php
// Match ISO date format
$router->add('GET', '/articles/{date:regex(\d{4}-\d{2}-\d{2})}', 'ArticleController::showAction');

// Match namespaced API routes (e.g., v1 and v2)
$router->add('GET', '/api/{namespace:regex(v[1-2])}/users', 'ApiController::usersAction');
```

The regex pattern is matched using PCRE's `preg_match()` function.

### Optional Parameters

Append `?` to a parameter name to make it optional. The segment is accepted when present and valid, but the route still matches when it is absent.

```php
// /users or /users/42 both match
$router->add('GET', '/users/{id?:int}', 'UserController::listOrViewAction');

// /archive or /archive/2026 or /archive/2026-01-15 all match
$router->add('GET', '/archive/{date?:regex(\d{4}(-\d{2}-\d{2})?)}', 'ArchiveController::indexAction');
```

In your action, declare the parameter as nullable or give it a default value:

```php
public function listOrViewAction(?int $id = null): Response
{
    if ($id === null) {
        // list all
    } else {
        // view single
    }
}
```

### Routing Variables

Certain parameter names have special meaning to the Dispatcher and control how controllers and actions are resolved:

```php
$router->add('GET', '/{controller}/{action}');
$router->add('GET', '/api/{namespace}/{controller}/{action}');
```

Routing variables recognized by the Dispatcher:

- `{namespace}` - Appends to the base namespace for controller resolution
- `{controller}` - Specifies the controller name (converted to PascalCase + 'Controller')
- `{action}` - Specifies the action method name (converted to camelCase + 'Action')

Example: `/api/admin/user/view` with pattern `/api/{namespace}/{controller}/{action}` yields:

- `namespace` → `Admin` (appended to base namespace)
- `controller` → `UserController`
- `action` → `viewAction`

**Note:** When you specify a handler (third parameter to `add()`), it overrides the routing variables. This lets you use parameters named `controller`, `action`, etc. for other purposes:

```php
// Uses routing variables to resolve controller/action dynamically
$router->add('GET', '/{controller}/{action}');

// Handler overrides - 'controller' becomes a regular parameter
$router->add('GET', '/admin/{controller}', 'AdminController::manageAction');
```

## HTTP Methods

A single method, an array of methods, or `*`/null to match all common methods (GET, POST, PUT, DELETE, PATCH, OPTIONS).

```php
$router->add('GET', '/users', 'UserController::listAction');
$router->add(['PUT', 'PATCH'], '/users/{id:int}', 'UserController::updateAction');
$router->add('*', '/health', 'HealthController::statusAction');
```

## Named Routes and URL Generation

```php
$router->add('GET', '/users/{id:int}', 'UserController::viewAction')
    ->setName('user.view');

$url = $router->urlFor('user.view', ['id' => 42], ['tab' => 'profile']);
// /users/42?tab=profile
```

## Custom Parameter Types

```php
$router->type('slug', fn(string $v) => preg_match('/^[a-z0-9-]+$/', $v));
$router->add('GET', '/posts/{slug:slug}', 'PostController::showAction');
```

## Route Priority

When multiple routes could match the same URL, the Router picks the most specific one — specificity is scored per segment:

| Segment kind                                | Score |
| ------------------------------------------- | ----- |
| Static literal                              | 3     |
| Typed / regex parameter                     | 2     |
| Untyped or wildcard (`{name}` / `{name:*}`) | 1     |

The route with the highest total score wins.

```php
// /users/settings matches the static route, not the parameter route
$router->add('GET', '/users/{id:int}', 'UserController::viewAction'); // score 5 (3+2)
$router->add('GET', '/users/settings', 'UserController::settingsAction'); // score 6 (3+3) ← wins
```

## Prefix, Namespace, Controller, and Middleware Groups

Use the grouping methods in two ways:

- Callback form: the group is temporary and the router restores the previous state when the callback returns.
- Inline form: omit the callback and the group stays active for subsequent routes in the current routing setup.

```php
// Inline middleware applies to later routes
$router->middleware('auth');
$router->add('GET', '/account', 'AccountController::indexAction');

// Inline prefix applies to later routes
$router->prefix('/admin');
$router->add('GET', '/dashboard', 'AdminController::indexAction');

// Inline namespace is prepended to later handlers
$router->namespace('Admin');
$router->add('GET', '/users', 'UserController::listAction');

// Inline controller supplies the controller part for later handlers
$router->controller('UserController');
$router->add('GET', '/users', '::listAction');

// Scoped callback forms still work when you want temporary grouping
$router->middleware(['auth', 'admin'], function (Router $r) {
    $r->add('DELETE', '/users/{id:int}', 'UserController::deleteAction');
});

$router->prefix('/api', function (Router $r) {
    $r->namespace('Api');
    $r->middleware('auth');
    $r->add('GET', '/orders', 'OrderController::listAction');
});
```

Inline groups can be combined, and callback-scoped groups automatically restore the previous stack when they finish.

## Dispatcher Configuration

The Dispatcher handles controller resolution and default values:

```php
$dispatcher = new Dispatcher();
$dispatcher->setBaseNamespace('\\App\\Controllers'); // Default: '\\App\\Controllers'
$dispatcher->setDefaultController('IndexController'); // Default: 'IndexController'
$dispatcher->setDefaultAction('indexAction');         // Default: 'indexAction'
```

> **Note:** The base namespace should start with a leading backslash (`\`). Relative namespace overrides from the Router's `namespace()` groups are appended to it automatically.

## Route Information in AppContext

When the Dispatcher processes a route, it stores the routing information in `AppContext->route` as a `ResolvedRoute` object. This makes the current route accessible throughout your application:

```php
// In any controller, middleware, or service:
$route = AppContext::instance()->route();

// Access route details:
$controller = $route->controller; // Full controller class name
$action = $route->action; // Action method name
$namespace = $route->namespace; // Resolved namespace
$vars = $route->vars; // All route variables
$params = $route->params; // Resolved arguments passed to the action
$groups = $route->groups; // Middleware groups
$override = $route->override; // Handler overrides
```

Action parameters resolve from route variables, then `AppContext` dependency
injection, then method defaults or `null`. See [Controllers & Views](03-CONTROLLERS-VIEWS.md#dependency-injection)
for the full resolution rules.

## Wildcard Parameters

A wildcard segment (`{segments:*}`) captures all remaining path segments as an `array`:

```php
$router->add('GET', '/files/{params:*}', 'FileController::readAction');

class FileController extends Controller
{
    // Variadic: each segment becomes a separate argument
    public function readAction(string ...$params): Response
    {
        $path = implode('/', $params); // e.g. 'images/2026/photo.jpg'
        // ...
    }
}
```

The parameter can also be declared plain `array` to receive all segments at once.

## DI Injection Example

Parameters that can't be matched by name fall through to DI resolution:

```php
// src/Services/Greeter.php
namespace App\Services;

class Greeter
{
    public function greet(string $name): string
    {
        return "Hello, $name!";
    }
}
```

```php
use Azera\AppContext;
use App\Services\Greeter;

$ctx = AppContext::instance();
// Lazy singleton: instantiated once, on first request.
$ctx->set(Greeter::class, fn() => new Greeter());
```

```php
use App\Services\Greeter;

class WelcomeController extends Controller
{
    // $name is matched by route; $greeter is injected from AppContext by type
    public function helloAction(string $name, Greeter $greeter): array
    {
        return [
            'message' => $greeter->greet($name)
        ];
    }
}
```

```php
$router->add('GET', '/hello/{name}', 'WelcomeController::helloAction');
// GET /hello/World → {"message":"Hello, World!"}
```

## Route Cache

A cold SAPI — php-fpm, php-cgi, Volucer's spawn_cgi — builds the whole route table
inside the timed request, because nothing survives between requests. On a 120-route
table that build is most of the framework's per-request cost, and it is pure
repetition: the same routes are registered on every request, forever.

The router can compile the table to a PHP file and `require` it instead.

```php
// Optional: choose where the table lives. Without this it is derived from the
// declared sources and written under the system temp directory.
$router->setCachePath(BASE_DIR . '/temp/routes.php');
$router->setRevalidateFreq(60);          // optional

// Declare the routes as one or more units. This is what turns the cache on, and
// `__FILE__` is what the cache watches.
$router->cached(__FILE__, function (Router $r): void {
    $r->get('/', 'IndexController::indexAction');
    $r->prefix('/clarity-engine', function (Router $r): void {
        $r->get('/docs/{segments:*}', 'Docs::docsAction');
    });
});
```

Nothing else is required. There is no `load()` call and no deploy step: the first
request that reads the router (`match()`, `allRoutes()`, `urlFor()`,
`hasNamedRoute()`, `getAllowedMethods()`) decides between restoring the file and
building the table, and **writes the file when it had to build**.

### Why `cached()` exists

Deciding whether to reuse the file requires reading it, which has to happen *before*
the routes are registered. `cached()` therefore only *records* a block; the block runs
later, and only if the table has to be rebuilt.

Declaring a unit is also what enables the cache. The file list has no other meaning, so
a separate `enableCache()` call would only be a way to write the whole feature and
forget to switch it on. `disableCache()` is the explicit opt-out; with it the blocks run
immediately and no file is ever touched. A route registered outside such ablock is refused, because nothing could tell when it went stale.

`__FILE__` — not a backtrace — is what gets watched. It is resolved at compile time, so
it names the file the block was *written* in however many helpers sit between the block
and `add()`.

Several units are allowed and run in declaration order, which is what keeps
specificity ties resolving the way a cold build did.

### Where the file lives

`setCachePath($path)` chooses the location. Without it the path is derived from the
declared sources:

```
sys_get_temp_dir()/azera_routes/routes-<hash>.php
```

The hash covers the absolute source paths and the effective uid, so two applications —
or two users — on one host never share a file, and adding or removing a route *file*
produces a new name rather than thrashing one shared table. A derived path is why the
resolution happens at the first read rather than when `cached()` is called.

The default is convenient, not durable: `/tmp` is swept by the OS, some service
managers give a unit a private `/tmp`, and a container may not preserve it between
deploys. Production deployments that warm the table in a deploy hook should pass an
explicit path inside the application, e.g. `BASE_DIR . '/temp/routes.php'`.

The directory is created `0700` and the table `0600`. A directory that is group- or
world-writable is refused — the file is `require`d, so loading from one would be
executing code another user can plant. When that happens the router quietly rebuilds in
memory instead of failing the request.

### Staleness

The compiled file records, per declared source, its mtime at compile time, plus the
compile timestamp. It is rebuilt when:

- any declared source's mtime changed
- **a source file was added or removed** (the declared set must match the recorded set)
- a source no longer exists
- a source's mtime equals the compile stamp — a deploy that finishes inside one clock
  tick must not be mistaken for fresh
- the envelope version differs

`filemtime()` is read after `clearstatcache()`. Without it PHP answers from its own stat
cache, which in a long-lived worker survives across requests and stops seeing edits.

### `revalidateFreq`

By default the file is checked once per process, at the first read. Under php-fpm that
is exactly one check per request, which is all that is needed.

In a long-lived worker (Volucer, RoadRunner) the router outlives the request, and
`setRevalidateFreq($seconds)` bounds how long a rebuilt table may go unnoticed — the
route-table analogue of `opcache.revalidate_freq` and of
`Clarity\Engine\Cache::setRevalidateFreq()`. Only positive verdicts are reused; a
stale verdict always triggers a rebuild on the next read.

A table this process wrote itself is authoritative while its file is unchanged: PHP's
OPcache will not cache a file the current process wrote, so re-reading it would cost a
full compile to swap in an identical table. Once another process replaces the file, the
new table is evaluated normally.

### Rules

- Every route must be inside a `cached()` block. A route registered before the first
  block is refused at the first read (it would silently vanish on the next rebuild), and
  a route registered after the table has been read is refused immediately.
- `cached()` may not nest, and may not be called inside a `prefix()`/`middleware()`/
  `namespace()`/`controller()` callback — the block would run later, outside that group.
- `type()` must be called before the units. Validators are closures, are not compiled,
  and a restored table never runs the blocks that register them.
- Routes and route middleware are data: handlers are strings or arrays of strings, and
  middleware are strings. Objects and closures are rejected.

### Writing

The file is written with `tmp` + `rename`, so a request can never include a half-written
table. A failed write is not fatal — the table in memory is already correct, and the
next request rebuilds. Call `warnOnWriteFailure()` to be told about it.

### CLI

```
azera routes:status          is the file there, and is it fresh?
azera routes:status --strict exit 1 unless it exists and is fresh (for CI)
azera routes:cache           rebuild now and write, ignoring the file
azera routes:clear           delete the file
azera routes:list            list the routed table
```

`routes:cache` reports the number of routes, the byte size, and every watched file.

### What this is worth

Measured on the VM, PHP 8.5, with the table in a shared OPcache — a warm `require` and
`restoreState()`, against building the same routes from their units in the same process:

| | per request |
|---|---|
| build 9 benchmark routes from their units | 14.2 µs (median), 1.58 µs per route |
| `require` + `restoreState()` | 0.4 µs |
| the homepage's real 124-declaration table (120 routes) | **191 µs → 5.7 µs, 33.5×** |

The homepage figure is the one that matters: its `registerRoutes()` uses nested
`prefix()`/`controller()` closures, so its build is dominated by closure and group
overhead rather than by route count. Measured by running that project's own
`registerRoutes()` on the VM, PHP 8.5, with a warm table in shared OPcache, 200
iterations each way, medians. The table the app itself compiles is 86 KB.

That 5.7 µs is what a request pays *after* the Router class and the table are already in
shared memory — the steady state of an FPM worker or a Volucer worker. The very first
request in a fresh process pays the class load and the one-time compile on top; see
*The one-time compile* below.

On the wire the cache took `/echo` at c=96 on nginx + php-fpm from **23,814 to 49,174
rps**, and the framework's own share of CPU per request from 339 µs to 86 µs.

Per-route costs about 0.6–1.6 µs to build depending on how deeply the registration is
grouped, so the saving scales with the table: a small app saves microseconds, and a
120-route app saves about 185 µs.

Backdating the written table's mtime is what makes this work. OPcache refuses to cache a
file whose mtime is within `opcache.file_update_protection` of now, so a table written
during a request would be recompiled by the *next* request — 529 µs per request under
php-fpm, worse than not caching at all. The table is therefore written with an mtime
older than that window, which is why the file's own mtime is not authoritative and the
freshness stamps live in the envelope.

### The one-time compile

The first `require` of a file in a process that has not compiled it before costs a full
compile: 0.44 ms for a 120-route table, 1.6 ms at 500 routes, 7.2 ms at 2000 on this
hardware. Under php-fpm that happens once per worker process unless the table is already
in shared OPcache memory, and shared memory is per FPM master — so a restart, or an
`opcache_reset()`, makes every worker pay it once.

Warm the table during deploy (`azera routes:cache`) so that compile lands in the deploy
step instead of the first production request. Every request after it loads and matches
the 120-route table in about 6 µs.

### Not measured here

Windows and some CLI builds have no shared OPcache, so a `require` there recompiles
every time (1.7 ms for 86 KB) and the hit path is *slower* than the build. That is a
property of the environment, not of the cache: check `opcache_is_script_cached()` before
drawing conclusions from a local timing.

## See Also

- [Controllers & Views](03-CONTROLLERS-VIEWS.md)
- [CLI Tasks](08-CLI-TASKS.md)
- [API Reference](api/README.md)
