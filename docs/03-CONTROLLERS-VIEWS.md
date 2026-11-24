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

| Method             | Returns                                                     |
| ------------------ | ----------------------------------------------------------- |
| `$this->context()` | `AppContext`                                                |
| `$this->request()` | `Azera\Http\Request`                                        |
| `$this->view()`    | `Azera\Core\ViewEngine`                                     |
| `$this->session()` | `Azera\Http\Session` or `null`                              |
| `$this->cookies()` | `Azera\Http\Cookies` — set cookies; emitted on the response |
| `$this->flash()` | `Azera\Http\Flash` — one-request messages                  |
| `$this->translator()` | `Azera\Localization\Translator` — translation service    |
| `$this->intl()`    | `Azera\Localization\Intl` — locale-aware formatting          |
| `$this->t()`       | Translated message for a key                                |

`$this->t()` is sugar for `$this->translator()->get()` and reads the same
catalogue as templates bound with `$translator->bind($view)`:

```php
return ['heading' => $this->t('order.heading', ['id' => $order->id])];
```

`$this->intl()` formats numbers, currency and dates with the same locale:

```php
return ['total' => $this->intl()->formatCurrency($order->total, 'EUR')];
```

See [Localization](12-LOCALIZATION.md).

---

## Returning Responses

The `Dispatcher` converts controller return values into HTTP responses:

| Return type                  | Response produced                        |
| ---------------------------- | ---------------------------------------- |
| `Azera\Http\Response`        | sent as-is                               |
| `array` / `JsonSerializable` | `200 application/json`                   |
| `string`                     | `200 text/html`                          |
| `int`                        | status-only response (e.g. `return 403`) |
| `null`                       | `204 No Content`                         |

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

## One-request Messages (Flash)

Flash messages support the POST-redirect-GET pattern. An action can queue a
message before redirecting, and the destination request can display it once.

```php
// POST /account
public function updateAction(): Response
{
    // … save …
    $this->flash()->success('Account settings updated.');

    return Response::redirect('/account');
}

// GET /account
public function accountAction(): string
{
    return $this->view()->render('account', [
        'messages' => $this->flash()->consume(),   // [['type' => 'success', 'message' => '…']]
    ]);
}
```

A message is queued for the **next** request. Use `now()` when rendering the
response in the current request, such as when redisplaying a form with a
validation error:

```php
$this->flash()->now('error', 'Enter a valid email address.');
return $this->view()->render('account', ['messages' => $this->flash()->consume()]);
```

| Method | Purpose |
| --- | --- |
| `success()`, `error()`, `warning()`, `notice()`, `info()` | Queue a typed message for the next request |
| `add(string $type, string $message)` | Queue any type; an unknown type uses its own name as the CSS class |
| `now(string $type, string $message)` | Show it on this request too |
| `consume(?string $type = null, bool $consume = true)` | Read (and by default remove) the visible messages |
| `first(?string $type = null)` | The first visible message, or `null` |
| `has(?string $type = null)` | Whether anything is visible |
| `pending()` | Messages queued for the next request |
| `reflash()` | Carry the visible messages over one more request |
| `render()` | Escape the visible messages into HTML blocks |
| `clear()` | Drop everything |

When a session is available, messages are stored there rather than in the URL.
In contexts without a session, such as CLI tasks and tests, the bag stores
messages in memory and exposes the same API.

The bag resolves the session lazily and promotes queued messages once a session
becomes available. This supports middleware orders where the bag is accessed
before `SessionMiddleware` initializes the session.

`render()` provides HTML markup for applications that do not use a template
partial. The message type determines the CSS class:

```php
echo $this->flash()->render();
// <div class="success" role="alert">Account settings updated.</div>
```

By default, `error` maps to `danger` and `notice` to `info`, following Bootstrap's
alert class names. Messages are HTML-escaped by default. CSS classes and the
template can be configured on the instance:

```php
$ctx->flash()
    ->setCssClasses(['success' => 'alert alert-success', 'error' => 'alert alert-danger'])
    ->setTemplate('<div class="%class%" role="alert">%message%</div>');
```

---

## HTTP Caching and Conditional GET

Conditional requests let a client reuse a cached response when its validator
matches. Set a validator, then use `notModifiedIfMatches()` to evaluate the
request:

```php
use Azera\Http\Response;

public function showAction(string $slug): Response
{
    $page = $this->docs()->page($slug);          // ['html' => …, 'mtime' => …, 'revision' => …]

    return Response::html($page['html'])
        ->setEtag($page['revision'])
        ->setLastModified($page['mtime'])
        ->setCache(3600, shared: true)
        ->notModifiedIfMatches($this->request());
}
```

On a match, the response becomes `304 Not Modified` with an empty body. The
validators and `Cache-Control` headers are retained, and headers describing the
response body, including `Content-Type`, are removed.

| Method | Effect |
| --- | --- |
| `setEtag(string)` | `ETag`, quoted for you; a weak tag (`W/"…"`) is preserved |
| `setLastModified(int\|DateTimeInterface)` | `Last-Modified` in IMF-fixdate, always GMT |
| `setExpires(int\|DateTimeInterface)` | `Expires` |
| `setCache(int $seconds, bool $shared, bool $immutable)` | `Cache-Control: …max-age=…` + `Expires` |
| `setNoCache()` | `no-store, no-cache, must-revalidate, max-age=0` + `Pragma` + past `Expires` |
| `isNotModified(Request)` | Whether the request's validators match |
| `notModifiedIfMatches(Request)` | Turn into a `304` on a match |

When both are present, `If-None-Match` takes precedence over `If-Modified-Since`,
as specified by RFC 9110. Comparison is weak (`W/"x"` matches `"x"`). Conditional
handling applies to `GET` and `HEAD` requests when a validator is set.

Set `$shared: true` for responses suitable for CDN storage. Personalized
responses should use private caching. Set `$immutable: true` for a fingerprinted
asset whose content does not change under its URL.

### Sending a file

```php
// Download (Content-Disposition: attachment)
return (new Response())->setFileToSend($path, 'invoice-' . $id . '.pdf');

// Display inline in the browser
return (new Response())->setFileToSend($path, 'logo.svg', attachment: false);
```

The response derives `Content-Type`, `Content-Length`, `Content-Disposition`,
`Last-Modified` and a size-and-mtime `ETag` from the file, and supports
conditional GET:

```php
return (new Response())
    ->setFileToSend($path, 'archive.zip')
    ->notModifiedIfMatches($this->request());
```

`send()` streams the file. `Response::xSendfile(true)`, configured at startup,
delegates file delivery to a web server with `X-Sendfile` enabled. In RoadRunner
and Volucer workers, `getBody()` reads the file, allowing the same response to be
used across deployment models.

---

## Cross-cutting concerns: use Middleware

The `Controller` base class has no `beforeAction()` or `afterAction()` hooks.
Use middleware for cross-cutting behavior such as authentication, logging, or
CORS. Middleware can run before and after an action by calling `$next()` and
then inspecting or changing its response.

For example, "require login before every action" is an auth middleware:

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        if (!$context->session()?->get('user_id')) {
            return Response::redirect('/login');
        }
        return $next(); // runs the action
    }
}
```

And "add a response header after every action" is a middleware that wraps the action:

```php
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $response = $next(); // run the action (and earlier middleware)
        if ($response instanceof Response) {
            $response->addHeader('X-Frame-Options', 'DENY');
        }
        return $response;
    }
}
```

Attach middleware to a controller or action with `$middlewares` or
`$actionMiddlewares`. Middleware can be reused, ordered, configured, and tested
independently.

---

## Middleware

Middleware wraps the entire request pipeline around controller actions. The execution order is:

```
Global middleware → Route-group middleware → Controller middleware → Action middleware → action
```

The dispatcher builds the pipeline in two stages. Global and route-group middleware
run before the controller is constructed; only its class name is needed at this stage.
If they reject the request, the controller is not constructed, the action is not
reflected, and its arguments are not resolved. Controller and action middleware run
after construction but before action reflection, so they can reject the request without
revealing whether the action exists.

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

> **Note:** as a *property default*, `$middlewares` and `$actionMiddlewares` must be
> constant expressions, so `new MyMiddleware()` is not allowed there — use the class
> string or `[class, args]` form. An instance is fine when the array is assigned in a
> constructor or passed to `defineMiddlewareGroup()`.

Because controller middleware is read from the instance, a subclass that redeclares
`$middlewares` **replaces** the parent's list rather than appending to it — normal PHP
property resolution. Use a shared parent class or the property's own list if you want
both.

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

**Absolute paths are rejected.** POSIX (`/var/www/views/custom.php`), Windows (`C:/views/custom.php`) and UNC (`\\server\share\custom.php`) names throw a `RuntimeException`. Names that resolve outside their root, after `.` and `..` segments are collapsed, also throw.

View names are always confined to their view path (or namespace root). Because view names are passed to `include`, an escape from this boundary would allow arbitrary local file inclusion. Request values should not be used as view names directly. Map them against an allowlist first:

```php
// UNSAFE – the client controls the include target
$this->view()->render($this->request()->get('page'));

// Safe – allowlist the permitted views
$allowed = ['home', 'about', 'contact'];
$page = in_array($this->request()->get('page'), $allowed, true) ? $this->request()->get('page') : 'home';

$this->view()->render($page);
```

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
- [Localization](12-LOCALIZATION.md)
- [MVC Routing](02-CORE-ROUTING.md)
- [HTTP Request](06-HTTP-REQUEST.md)
- [API Reference](api/README.md)
