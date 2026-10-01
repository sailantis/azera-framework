# Cookbook

**Practical recipes** – pagination, soft delete, transactions, subqueries, and more, built with the current Azera API.

## 1) Paginated Listing

See [Pagination with Paginator](05-DATABASE-QUERIES.md#pagination-with-paginator) for the full API:

```php
$paginator = User::query()
    ->where('status', 'active')
    ->orderBy('created_at DESC')
    ->paginate(page: 2, pageSize: 20);

$users = $paginator->entities(); // identity-mapped User instances for page 2
```

## 2) Find or Create

```php
$user = User::firstOrCreate(
    ['email' => 'jane@example.com'],
    ['username' => 'jane']
);
```

## 3) Update or Create

```php
$user = User::updateOrCreate(
    ['email' => 'jane@example.com'],
    ['username' => 'jane.doe', 'status' => 'active']
);
```

## 4) Search by Dynamic Filters

```php
$query = User::query();

if (!empty($filters['email'])) {
    $query->where('email', $filters['email']);
}

if (!empty($filters['created_after'])) {
    $query->where('created_at >= :created_after', ['created_after' => $filters['created_after']]);
}

if (!empty($filters['roles'])) {
    $query->inWhere('role', $filters['roles']);
}

$rows = $query->orderBy('id DESC')->select();
```

## 5) Safe Bulk Update

```php
$affected = User::query()
    ->where('last_login < :cutoff', ['cutoff' => '2025-01-01'])
    ->update(['status' => 'inactive']);
```

## 6) Soft Delete Pattern

Mark records as deleted with a timestamp instead of permanently deleting them:

```php
class Post extends \Azera\Orm\Model
{
    public int $id;
    public string $title;
    public ?string $deleted_at = null;

    public function softDelete(): bool
    {
        $this->deleted_at = date('Y-m-d H:i:s');
        return $this->save();
    }
}
```

Every read then needs `->where('deleted_at', null)` — see recipe 4.

## 7) Transaction with Multiple Writes

```php
use Azera\AppContext;

$db = AppContext::instance()->dbManager()->getDefault();

$db->begin();
try {
    $orderId = Order::query()->insert([
        'user_id' => 1,
        'status' => 'open',
    ]);

    OrderItem::query()->insert([
        'order_id' => $orderId,
        'product_id' => 2,
        'qty' => 3,
    ]);

    Product::query()
        ->where('id', 2)
        ->update(['stock' => new \Azera\Db\Sql('stock - 3')]);

    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

## 8) Read/Write Split

```php
use Azera\AppContext;
use Azera\Db\Database;

$ctx = AppContext::instance();
$ctx->dbManager()->set('write', new Database('mysql:host=primary;dbname=app', 'rw', 'secret'));
$ctx->dbManager()->set('read',  new Database('mysql:host=replica;dbname=app', 'ro', 'secret'));

$users = User::findAll(['status' => 'active']); // read

$user = User::find(1);
$user->status = 'inactive';
$user->save(); // write
```

## 9) Route + Dispatcher Integration

```php
$router->add('GET', '/users/{id:int}', 'UserController::viewAction');
$route = $router->match('/users/7', 'GET');

if ($route !== null) {
    $response = $dispatcher->dispatch($route);
    $response->send();
}
```

## 10) CLI Cleanup Task

```php
class CleanupTask extends \Azera\Cli\Task
{
    public function sessionsAction(int $days = 30): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = Session::query()
            ->where('last_seen < :cutoff', ['cutoff' => $cutoff])
            ->delete();

        echo "Deleted {$deleted} sessions\n";
    }
}
```

## 11) Subquery as Derived Table (FROM)

See [FROM Subquery](05-DATABASE-QUERIES.md#from-subquery) — a `Query` as the `FROM` source; bind parameters carry over automatically:

```php
use Azera\Db\Query;

// Step 1 — build the inner query independently
$activeSales = Query::raw()
    ->table('orders')
    ->where('status', 'completed')
    ->where('created_at > :since', ['since' => '2025-01-01'])
    ->groupBy('user_id')
    ->columns(['user_id', 'SUM(total) AS revenue']);

// Step 2 — wrap it as a derived table
$topCustomers = Query::raw()
    ->from($activeSales, 'sales')   // alias required so outer query can reference columns
    ->where('sales.revenue >', 1000)
    ->orderBy('sales.revenue DESC')
    ->limit(10)
    ->select();
```

## 12) Subquery in JOIN

See [Subquery in JOIN](05-DATABASE-QUERIES.md#subquery-in-join) — any join method accepts a `Query` instance:

```php
use Azera\Db\Query;

// Aggregate products to their latest price
$latestPrices = Query::raw()
    ->table('price_history')
    ->where('effective_date <= :today', ['today' => date('Y-m-d')])
    ->groupBy('product_id')
    ->columns(['product_id', 'MAX(price) AS current_price']);

$catalogue = Query::raw()
    ->table('products', 'p')
    ->leftJoin($latestPrices, 'lp', 'lp.product_id = p.id')
    ->columns(['p.name', 'p.sku', 'lp.current_price'])
    ->where('p.active', 1)
    ->orderBy('p.name')
    ->select();
```

## 13) Validate Form Input and Save

Combine the `Validator` with model methods to validate, coerce, and persist data in one clean flow. `$v->validated()` returns only the fields that passed, which drops any unexpected input automatically.

```php
use Azera\Validation\Validator;
use Azera\Http\Response;
use Azera\Core\Controller;

class UserController extends Controller
{
    public function createAction(): Response|array
    {
        $v = new Validator($this->request()->post());

        $v->field('name')->required()->string()->min(2)->max(100);
        $v->field('email')->required()->email()->max(255);
        $v->field('role')->required()->in(['admin', 'editor', 'viewer']);
        $v->field('bio')->optional()->string()->max(500);

        if ($v->fails()) {
            return Response::json(['errors' => $v->errors()], 422);
        }

        $user = User::create($v->validated());

        return ['id' => $user->id, 'email' => $user->email];
    }

    public function updateAction(int $id): Response|array
    {
        $user = User::findOrFail($id);

        $v = new Validator($this->request()->post());
        $v->field('name')->optional()->string()->min(2)->max(100);
        $v->field('email')->optional()->email()->max(255);

        if ($v->fails()) {
            return Response::json(['errors' => $v->errors()], 422);
        }

        foreach ($v->validated() as $key => $value) {
            $user->$key = $value;
        }
        $user->save();

        return ['id' => $user->id];
    }
}
```

## 14) JSON API Controller

Return arrays directly from action methods — the `Dispatcher` automatically serialises them as `application/json`. Use `Response::json()` when you need a non-200 status code.

```php
use Azera\Http\Response;
use Azera\Core\Controller;

class ArticleController extends Controller
{
    // GET /articles → 200 JSON
    public function indexAction(): array
    {
        $articles = Article::findAll(['published' => 1]);

        return array_map(fn($a) => [
            'id'    => $a->id,
            'title' => $a->title,
            'slug'  => $a->slug,
        ], $articles);
    }

    // GET /articles/{id} → 200 JSON or 404
    public function showAction(int $id): Response|array
    {
        $article = Article::find($id);
        if ($article === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        return ['id' => $article->id, 'title' => $article->title, 'body' => $article->body];
    }

    // DELETE /articles/{id} → 204 No Content
    public function deleteAction(int $id): ?Response
    {
        $article = Article::findOrFail($id);
        $article->delete();

        return Response::status(204);
    }
}
```

## 15) Session-based Authentication

The session is activated by `SessionMiddleware` in the dispatcher setup:

```php
// bootstrap — register the session middleware once
$dispatcher->addMiddleware(new \Azera\Http\SessionMiddleware());
```

```php
use Azera\Http\Response;
use Azera\Core\Controller;

class AuthController extends Controller
{
    public function loginAction(): Response|array
    {
        $email    = $this->request()->post('email', '');
        $password = $this->request()->post('password', '');

        $user = User::findOne(['email' => $email]);

        if ($user === null || !password_verify($password, $user->password_hash)) {
            return Response::json(['error' => 'Invalid credentials'], 401);
        }

        $this->session()->set('user_id', $user->id);
        session_regenerate_id(true); // prevent session fixation

        return ['ok' => true];
    }

    public function logoutAction(): Response
    {
        $this->session()->clear();
        return Response::redirect('/login');
    }
}
```

Protect any controller by attaching an auth middleware to its `$middlewares` property:

```php
use Azera\Http\Response;
use Azera\Core\Controller;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        if (!$context->session()?->get('user_id')) {
            return Response::redirect('/login');
        }
        return $next($context);
    }
}

class AccountController extends Controller
{
    protected array $middlewares = [AuthMiddleware::class];

    public function dashboardAction(): string
    {
        $userId = $this->session()->get('user_id');
        $user   = User::find($userId);

        return $this->view()->render('account/dashboard', ['user' => $user]);
    }
}
```

## 16) CSRF Protection

Azera ships a built-in `Azera\Security\CsrfMiddleware` (synchronizer token pattern) — see [Security (Enterprise)](18-SECURITY-ENTERPRISE.md):

```php
use Azera\Security\CsrfMiddleware;

$dispatcher->addMiddleware(new CsrfMiddleware());
$token = (new CsrfMiddleware())->ensureToken($ctx->session());  // pass to the view
```

A hand-rolled session-token variant:

```php
// helpers.php — include in your bootstrap
function csrf_token(): string
{
    $session = \Azera\AppContext::instance()->session();
    if (!$session->has('csrf_token')) {
        $session->set('csrf_token', bin2hex(random_bytes(32)));
    }
    return $session->get('csrf_token');
}

function csrf_verify(): bool
{
    $session = \Azera\AppContext::instance()->session();
    $token   = \Azera\AppContext::instance()->request()->post('_csrf_token');
    return hash_equals($session->get('csrf_token', ''), (string) $token);
}
```

Embed the token in every HTML form:

```php
<!-- views/posts/create.php -->
<form method="post" action="/posts">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    ...
</form>
```

Validate server-side with a middleware:

```php
public function process(AppContext $context, callable $next): ?Response
{
    $method = $context->request()->getMethod();
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !csrf_verify()) {
        return \Azera\Http\Response::status(419); // token mismatch
    }
    return $next($context);
}
```

## 17) File Upload

Access uploaded files through `Request::file()` (single) or `Request::files()` (all). Each entry is an `UploadedFile` instance — see [File Uploads](06-HTTP-REQUEST.md#file-uploads).

```php
use Azera\Http\Response;
use Azera\Core\Controller;

class AvatarController extends Controller
{
    public function uploadAction(): Response|array
    {
        $file = $this->request()->file('avatar');

        if ($file === null || !$file->isValid()) {
            return Response::json(['error' => 'No valid file uploaded'], 422);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file->clientMediaType(), $allowed, true)) {
            return Response::json(['error' => 'Only JPEG, PNG, and WebP are allowed'], 422);
        }

        if ($file->size() > 2 * 1024 * 1024) { // 2 MB
            return Response::json(['error' => 'File must be under 2 MB'], 422);
        }

        $filename = bin2hex(random_bytes(8)) . '-' . basename($file->clientFilename());
        $dest     = __DIR__ . '/../../public/uploads/' . $filename;

        $file->moveTo($dest);

        return ['url' => '/uploads/' . $filename];
    }
}
```

## 18) Custom Middleware

Implement `MiddlewareInterface` to add cross-cutting behavior — rate limiting, API key auth, CORS headers, etc. Return `null` to pass through; return a `Response` to short-circuit.

```php
<?php
namespace App\Middleware;

use Azera\AppContext;
use Azera\Http\Response;
use Azera\Core\MiddlewareInterface;

class ApiKeyMiddleware implements MiddlewareInterface
{
    private array $validKeys;

    public function __construct(array $validKeys)
    {
        $this->validKeys = $validKeys;
    }

    public function process(AppContext $context, callable $next): ?Response
    {
        $key = $context->request()->server('HTTP_X_API_KEY', '');

        if (!in_array($key, $this->validKeys, true)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return $next($context); // continue the pipeline
    }
}
```

Register globally or as a named group:

```php
// every request
$dispatcher->addMiddleware(new App\Middleware\ApiKeyMiddleware(['key-abc', 'key-xyz']));

// or only for routes inside the 'api' group
$dispatcher->defineMiddlewareGroup('api', [
    new App\Middleware\ApiKeyMiddleware(['key-abc', 'key-xyz']),
]);

$router->middleware('api');
$router->add('GET', '/api/users', 'Api\UserController::indexAction');
```

## 19) Encrypting Sensitive Data

Use the same authenticated encryption the encrypted cookie uses — libsodium ChaCha20-Poly1305 (preferred) or AES-256-GCM via OpenSSL. Keys should be 32 bytes of random data, loaded from an environment variable or secrets manager — never hard-coded. See [Security](09-SECURITY.md#encryption-internals).

```php
// Generate a key once and store it securely:
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

## 20) Transactional Service with AOP

```php
use Azera\Aop\Advised;
use Azera\Aop\Transactional;
use Azera\Aop\TransactionalInterceptor;

#[Advised]
class OrderService
{
    #[Transactional]
    public function placeOrder(int $customerId, array $items): Order
    {
        $order = Order::create(['customer_id' => $customerId, ...]);

        foreach ($items as $item) {
            OrderItem::create(['order_id' => $order->id, ...]);
        }

        return $order;
    }
}

// bootstrap
$ctx->registerInterceptor(Transactional::class, new TransactionalInterceptor($ctx->dbManager()));
$ctx->set(OrderService::class); // autowired — proxy generated
```

No manual `begin/commit/rollback` — the interceptor handles it. See [AOP](16-AOP.md).

## 21) Caching Method Results

```php
use Azera\Aop\Advised;
use Azera\Aop\Cache;
use Azera\Aop\CacheInterceptor;

#[Advised]
class ReportService
{
    #[Cache(ttl: 300, key: 'report_{type}_{date}')]
    public function getReport(string $type, string $date): array
    {
        // Runs only on cache miss; result cached for 300 seconds
        return $this->buildExpensiveReport($type, $date);
    }
}

// bootstrap
$ctx->registerInterceptor(Cache::class, new CacheInterceptor($ctx->cache()));
```

The `{type}` and `{date}` placeholders are interpolated from the method arguments. See [AOP](16-AOP.md) and [Cache](14-CACHE.md).

## 22) Dispatching Events

See [Events](13-EVENTS.md) for the full listener API:

```php
// Event class
class OrderShipped
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $trackingNumber,
    ) {}
}

// Dispatch
$ctx->events()->dispatch(new OrderShipped($order->id, $tracking));

// Listen (in bootstrap)
$dispatcher->listen(OrderShipped::class, function (OrderShipped $event) use ($ctx) {
    $ctx->logger()->info('Order shipped', ['id' => $event->orderId]);
});
```

Class-string listeners are autowired through AppContext (constructor dependencies injected):

```php
class ShipNotificationListener
{
    public function __construct(private SmtpMailer $mailer) {}

    public function __invoke(OrderShipped $event): void
    {
        $this->mailer->send(...);
    }
}

$dispatcher->listen(OrderShipped::class, ShipNotificationListener::class);
```

## 23) Rate Limiting an Endpoint

```php
use Azera\Security\RateLimiter;

$limiter = new RateLimiter($ctx->cache());
$ip = $ctx->request()->server('REMOTE_ADDR', '0.0.0.0');

if (!$limiter->limit('api:' . $ip, 100, 60)) {
    return Response::json(['error' => 'Rate limit exceeded'], 429);
}
```

> Requires a persistent PSR-16 cache (Redis/Memcached) for multi-process rate limiting. `ArrayCache` only works within a single process. See [Security](18-SECURITY-ENTERPRISE.md).

## 24) CSRF Protection

The built-in middleware handles it — see [CSRF Protection](18-SECURITY-ENTERPRISE.md#csrf-protection):

```php
use Azera\Security\CsrfMiddleware;

$dispatcher->addMiddleware(new CsrfMiddleware());
$token = (new CsrfMiddleware())->ensureToken($ctx->session());  // pass to the view
```

## 25) Password Hashing

See [Password Hashing](18-SECURITY-ENTERPRISE.md#password-hashing) for options; the native API is equally fine:

```php
use Azera\Security\Hasher;

$hasher = new Hasher();

// On registration
$user->password_hash = $hasher->make($plainPassword);
$user->save();

// On login
if ($hasher->verify($inputPassword, $user->password_hash)) {
    // Check if hash needs upgrading
    if ($hasher->needsRehash($user->password_hash)) {
        $user->password_hash = $hasher->make($inputPassword);
        $user->save();
    }
    // Login successful
}
```
