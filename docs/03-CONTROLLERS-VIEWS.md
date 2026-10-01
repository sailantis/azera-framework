# Controllers & Views

**Request handling and presentation** – controllers, dependency injection, view rendering, layouts, and middleware.

Controllers coordinate request handling and return responses. View rendering is handled by `Azera\Core\ViewEngine`.

---

## Controller Basics

Extend `Azera\Core\Controller` and add public methods whose names end with `Action`. The `Dispatcher` resolves the controller and action from the matched route, injects dependencies, and invokes the action.

```php
<?php
namespace App\Controllers;

use Azera\Core\Controller;

class UserController extends Controller
{
    public function indexAction(): string
    {
        return 'User index';
    }
}
```

---

## Dependency Injection

Action method parameters are resolved automatically by the `Dispatcher` in this priority order:

1. **Route parameter** — matched by parameter name (e.g. `{id}` → `$id`)
2. **DI / type-hint** — resolved from `AppContext` by type name (auto-wired if needed)
3. **Default value** — from the method signature
4. **Nullable** — injected as `null`

```php
use Azera\Db\DatabaseManager;

class UserController extends Controller
{
    // $id comes from the route; $db is DI-resolved from AppContext
    public function viewAction(int $id, DatabaseManager $db): array
    {
        $user = User::find($id);
        return $user ? ['id' => $user->id, 'email' => $user->email] : [];
    }
}
```

Constructor parameters on the controller itself are also auto-wired via `AppContext::get()`.

---

## Available Controller Helpers

All helpers delegate to `AppContext` and are available anywhere inside the controller:

| Method             | Returns                         |
| ------------------ | ------------------------------- |
| `$this->context()` | `AppContext`                    |
| `$this->request()` | `Azera\Http\Request`           |
| `$this->view()`    | `Azera\Core\ViewEngine`         |
| `$this->session()` | `Azera\Http\Session` or `null` |
| `$this->cookies()` | `Azera\Http\Cookies`           |

---

## Returning Responses

The `Dispatcher` converts controller return values into HTTP responses:

| Return type                  | Response produced                        |
| ---------------------------- | ---------------------------------------- |
| `Azera\Http\Response`       | sent as-is                               |
| `array` / `JsonSerializable` | `200 application/json`                   |
| `string`                     | `200 text/html`                          |
| `int`                        | status-only response (e.g. `return 403`) |
| `null`                       | `204 No Content`                         |

The same table is repeated in [Architecture](01-ARCHITECTURE.md#request-flow-web); this page is the reference.
```php
use Azera\Http\Response;

class HealthController extends Controller
{
    public function pingAction(): array
    {
        return ['ok' => true];   // → 200 JSON
    }

    public function movedAction(): Response
    {
        return Response::redirect('/new-location');
    }

    public function adminOnlyAction(): int
    {
        return 403;   // → 403 status-only response
    }
}
```

---

## Cross-cutting concerns: use Middleware

The `Controller` base class does **not** provide `beforeAction()`/`afterAction()` hooks. All cross-cutting behavior — authentication, logging, CORS, response modification, teardown — is expressed as **middleware**. A middleware can run code both *before* and *after* the action by invoking `$next()` and then inspecting or mutating its result.

For example, "require login before every action" is an auth middleware:

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        if (!$context->session()?->get('user_id')) {
            return Response::redirect('/login');
        }
        return $next($context); // runs the action
    }
}
```

And "add a response header after every action" is a middleware that wraps the action:

```php
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $response = $next($context); // run the action (and earlier middleware)
        if ($response instanceof Response) {
            $response->setHeader('X-Frame-Options', 'DENY');
        }
        return $response;
    }
}
```

Attach these to a controller (or a specific action) via the `$middlewares` / `$actionMiddlewares` properties below. This is strictly more flexible than dedicated hooks — middleware is reusable across controllers, orderable, configurable, and unit-testable in isolation.

---

## Middleware

Middleware wraps the entire request pipeline around controller actions. The execution order is:

```
Global middleware → Route-group middleware → Controller middleware → Action middleware → action
```

Each middleware implements `Azera\Core\MiddlewareInterface`:

```php
interface MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response;
}
```

Return `null` (or `$next($context)`) to pass control to the next layer. Return a `Response` to short-circuit the rest of the pipeline.

### Global Middleware

Registered on the `Dispatcher` — runs on every request before any controller or action-specific middleware:

```php
$dispatcher->addMiddleware(new SessionMiddleware());
$dispatcher->addMiddleware(new CorsMiddleware());
```

### Named Middleware Groups

Define groups on the `Dispatcher` and attach them inline with `Router::middleware()`:

```php
// bootstrap
$dispatcher->defineMiddlewareGroup('auth', [new AuthMiddleware()]);
$dispatcher->defineMiddlewareGroup('admin', [new AuthMiddleware(), new RoleMiddleware('admin')]);

// routing
$router->middleware('auth');
$router->add('GET', '/account', 'AccountController::indexAction');

$router->middleware('admin');
$router->add('GET',    '/admin/users',      'Admin\UserController::indexAction');
$router->add('DELETE', '/admin/users/{id}', 'Admin\UserController::deleteAction');
```

### Controller-Level Middleware

Declared as a protected property — runs for every action in the controller:

```php
class AdminController extends Controller
{
    protected array $middlewares = [
        AuthMiddleware::class,                       // instantiated with no args
        [RoleMiddleware::class, ['admin']],           // instantiated with constructor args
    ];
}
```

### Action-Level Middleware

Declared per action name — runs only for that specific action, after controller middleware:

```php
class UserController extends Controller
{
    protected array $middlewares = [AuthMiddleware::class];

    protected array $actionMiddlewares = [
        'deleteAction' => [
            [RoleMiddleware::class, ['admin']],
        ],
        'exportAction' => [
            ThrottleMiddleware::class,
        ],
    ];

    public function deleteAction(int $id): int
    {
        User::find($id)?->delete();
        return 204;
    }
}
```

### Middleware Definitions

All three places (`$middlewares`, `$actionMiddlewares`, group arrays) accept the same definition formats:

| Format                                  | Behavior                                           |
| --------------------------------------- | -------------------------------------------------- |
| `MyMiddleware::class`                   | Instantiated with `new MyMiddleware()`             |
| `[MyMiddleware::class, [$arg1, $arg2]]` | Instantiated with `new MyMiddleware($arg1, $arg2)` |
| `new MyMiddleware()`                    | Used as-is (already an instance)                   |
| `fn($ctx, $next) => $next($ctx)`        | Closure, wrapped automatically                     |

---

## ViewEngine Basics

The `ViewEngine` API is shared by all engines. The default engine is `ClarityEngine`, which compiles `.clarity.html` templates with auto-escaping, template inheritance, and a filter pipeline. See [Clarity Engine](03b-CLARITY-ENGINE.md) for the full syntax reference.

In the bootstrap:

```php
use Azera\AppContext;

$view = AppContext::instance()->view();
$view->setViewPath(__DIR__ . '/../views');
$view->setLayout('layouts/main'); // wraps every render() call
```

Render inside a controller:

```php
class PageController extends Controller
{
    public function homeAction(): string
    {
        return $this->view()->render('home/index', [
            'title'   => 'Home',
            'message' => 'Welcome',
        ]);
    }
}
```

The rendered view is injected into the layout. For partials (no layout), use `renderPartial()`:

```php
$html = $this->view()->renderPartial('partials/header', ['user' => $user]);
```

---

## View Name Resolution

The `ViewEngine` resolves view names to filesystem paths using the following rules:

**Relative names** — dot-notation is converted to directory separators:

- `users.index` → `users/index.php`
- `admin.users.edit` → `admin/users/edit.php`

**Namespaced views** — `namespace::view.name` resolves to the namespace root:

- `admin::dashboard.index` → `{admin-path}/dashboard/index.php`

**Dot-prefixed paths** — treated as literal relative paths:

- `./partials/header` → `./partials/header.php`

**Absolute paths** — used as-is:

- `/var/www/views/custom.php`

---

## View Variables

Variables can be set globally on the `ViewEngine` or passed per render:

```php
// Global — available in all views rendered through this engine
$this->view()->setVar('appName', 'MyApp');
$this->view()->setVars(['locale' => 'en', 'user' => $currentUser]);

// Per render
$html = $this->view()->render('user/index', [
    'users' => User::findAll(),
    'title' => 'All Users',
]);
```

---

## View Namespaces

Namespaces let you organize views from different parts of the application under distinct roots:

```php
// bootstrap
$view->addNamespace('admin', __DIR__ . '/../views/admin');
$view->addNamespace('mail',  __DIR__ . '/../views/email');

// usage
echo $this->view()->render('admin::users.index');
echo $this->view()->renderPartial('mail::welcome', ['user' => $user]);
```

---

## Validating Input

```php
use Azera\Validation\Validator;
use Azera\Validation\ValidationException;

class UserController extends Controller
{
    public function createAction(): array
    {
        $v = new Validator($this->request()->post());
        $v->field('name')->string()->min(2)->max(100);
        $v->field('email')->email()->max(255);
        $v->field('role')->optional()->in(['admin', 'editor', 'viewer']);

        if ($v->fails()) {
            return ['success' => false, 'errors' => $v->errors()];
        }

        $user = User::create($v->validated());
        return ['success' => true, 'id' => $user->id];
    }
}
```

See [Validation](07-VALIDATION.md) for the complete rule reference.

---

## Related

- [Clarity Engine](03b-CLARITY-ENGINE.md)
- [MVC Routing](02-CORE-ROUTING.md)
- [HTTP Request](06-HTTP-REQUEST.md)
- [API Reference](api/README.md)
