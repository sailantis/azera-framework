# AOP — Aspect-Oriented Programming

Cross-cutting concerns (transactions, caching, retry, logging) applied declaratively via PHP 8 attributes.

Three approaches, from simplest to most automated:

1. **Pipeline helper** — wrap a single callable, no proxy
2. **Proxy-based AOP** — `#[Advised]` + method attributes, automatic interception
3. **Custom interceptors** — compose interceptors manually

## Pipeline Helper

The `Pipeline` is the simplest form — wrap any callable with interceptors, no proxy generation:

```php
$result = $ctx->pipeline()
    ->through([new RetryInterceptor(3)])
    ->call(fn() => $service->chargeCard(100));
```

## Proxy-Based AOP

### How it works

1. Mark a class with `#[Advised]`
2. Mark methods with advice attributes (`#[Transactional]`, `#[Cache]`, etc.)
3. Register the corresponding interceptors in `AppContext`
4. Register the service for **autowiring** (class string, not a factory)

When `AppContext::build()` instantiates the class, it detects `#[Advised]` and generates a proxy class that:

- Extends the target class (the proxy **is** the instance)
- Overrides advised methods to wrap `parent::method()` in the interceptor chain
- Leaves non-advised methods untouched (zero overhead)

Proxy classes are written to disk (configurable cache dir) and OPcache'd. An `eval()` fallback is available for development.

### Enabling AOP

```php
use Azera\Aop\Transactional;
use Azera\Aop\TransactionalInterceptor;
use Azera\Aop\Cache as CacheAdvice;
use Azera\Aop\CacheInterceptor;

// 1. Set AOP cache directory (for file-based proxy generation)
$ctx->setAopCacheDir(BASE_DIR . '/temp/aop');

// 2. Register interceptors for each advice type
$ctx->registerInterceptor(Transactional::class, new TransactionalInterceptor($ctx->dbManager()));
$ctx->registerInterceptor(CacheAdvice::class, new CacheInterceptor($ctx->cache()));
```

### Using AOP

```php
use Azera\Aop\Advised;
use Azera\Aop\Transactional;
use Azera\Aop\Cache;

#[Advised]
class OrderService
{
    #[Transactional]
    public function createOrder(array $data): Order
    {
        // No manual begin/commit/rollback — the interceptor handles it
        $order = Order::create($data);
        OrderItem::create(['order_id' => $order->id, ...]);
        return $order;
    }

    #[Cache(ttl: 60, key: 'order_{id}')]
    public function getOrder(int $id): ?Order
    {
        return Order::find($id);
    }
}
```

### Critical: autowiring vs factory

The service **must** be registered as a class string (autowiring) for the proxy to be generated. `AppContext::build()` is only called when the definition is a class string — if you use a factory closure, the returned object is stored directly and no proxy is created:

```php
// Correct: build() generates the proxy
$ctx->set(OrderService::class);

// Wrong: factory returns a plain instance, no proxy
$ctx->set(OrderService::class, fn() => new OrderService(...));
```

Constructor dependencies are resolved automatically from the container during autowiring.

## Built-in Advice Attributes

### #[Transactional]

Wraps the method in a database transaction:

```php
#[Transactional]
public function transferMoney(int $from, int $to, float $amount): void
{
    // begin() called before, commit() after, rollback() on exception
}
```

- Uses `DatabaseManager::getOrDefault('write')` by default
- Supports savepoint nesting (nested `#[Transactional]` methods use savepoints)
- Specify a connection role: `#[Transactional(connection: 'analytics')]`

### #[Cache(ttl: int, key: ?string)]

Caches the method's return value:

```php
#[Cache(ttl: 300, key: 'user_profile_{id}')]
public function getProfile(int $id): array
{
    // Runs only on cache miss; result cached for 300 seconds
}
```

- `ttl` — time-to-live in seconds
- `key` — cache key with `{argName}` placeholders (auto-generated if omitted)
- Uses `AppContext::cache()` (PSR-16)

### #[Retry(times: int, backoff: int)]

Retries the method on failure:

```php
#[Retry(times: 3, backoff: 100)]
public function callExternalApi(): Response
{
    // Retried up to 3 times, sleeping 100ms between attempts
    // Last exception is re-thrown if all attempts fail
}
```

### #[Log(level: string, logArgs: bool)]

Logs method entry, exit, duration, and exceptions:

```php
#[Log(level: 'info', logArgs: true)]
public function processPayment(int $orderId): void
{
    // Logs: "processPayment called with (orderId=123)"
    // Logs: "processPayment returned in 45ms"
    // On exception: "processPayment failed: <exception>"
}
```

## Writing Custom Interceptors

### 1. Create an advice attribute

```php
use Azera\Aop\Advice;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Audited extends Advice
{
    public function __construct(
        public readonly string $action,
    ) {}
}
```

### 2. Create an interceptor

```php
use Azera\Aop\InterceptorInterface;
use ReflectionMethod;

class AuditInterceptor implements InterceptorInterface
{
    public function __construct(
        private AuditLog $auditLog,
    ) {}

    public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed
    {
        $advice = $this->getAdvice($method);
        $result = $next($args);

        $this->auditLog->record($advice->action, [
            'method' => $method->getName(),
            'class' => $target::class,
        ]);

        return $result;
    }

    private function getAdvice(ReflectionMethod $method): ?Audited
    {
        $attrs = $method->getAttributes(Audited::class);
        return $attrs === [] ? null : $attrs[0]->newInstance();
    }
}
```

### 3. Register and use

```php
$ctx->registerInterceptor(Audited::class, new AuditInterceptor($auditLog));

#[Advised]
class OrderService
{
    #[Audited(action: 'order.cancelled')]
    public function cancelOrder(int $id): void { ... }
}
```

## Performance

| Scenario                                           | Cost                                                  |
| -------------------------------------------------- | ----------------------------------------------------- |
| No interceptors registered                         | Zero — `build()` returns a plain instance             |
| Interceptors registered, class has no `#[Advised]` | Zero — `build()` returns a plain instance             |
| Class has `#[Advised]` but no advised methods      | One-time `ReflectionMethod` scan, then plain instance |
| Class has `#[Advised]` with advised methods        | One-time proxy generation, then OPcache'd proxy class |

Non-advised methods on a proxied class have **zero overhead** — they are not overridden and call the parent directly.
