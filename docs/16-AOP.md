# AOP — Aspect-Oriented Programming

Cross-cutting concerns (transactions, caching, retry, logging) applied declaratively via PHP 8 attributes.

## The model

One invocation object, one interceptor contract, one chain implementation.

1. **`Azera\Aop\Invocation`** — the single context object passed to every interceptor.
2. **`Azera\Aop\InterceptorInterface`** — `intercept(Invocation $invocation, callable $next): mixed`.
3. **`Azera\Aop\Pipeline`** — precomposes the chain once, in the constructor.
4. **`Azera\Aop\ProxyFactory`** — a code generator. It reads advice attributes once, emits a subclass whose overrides build one `Invocation` and run a precomposed `Pipeline`. There is no second engine and no per-call reflection.

```
attributes ──reflection, once──► generated class (one Invocation per call)
                                      │
                                      └─► AopRuntime::plan() memoizes the Pipeline per (class, method)
```

Reflection is a **build-time** cost of the transparent layer, not a runtime cost of interception. Nothing in the hot path reflects.

> **Upgrading from ≤ 0.6?** See [UPGRADING.md](UPGRADING.md) — the `intercept()` signature changed and `PipelineHandler` was removed. `Pipeline::through()` is deprecated but still works.

## The contract

```php
namespace Azera\Aop;

interface InterceptorInterface
{
    public function intercept(Invocation $invocation, callable $next): mixed;
}
```

`$next` is a precomposed frame. Calling it runs the next interceptor, or the wrapped callable at the innermost frame.

`Invocation` carries everything an interceptor can observe:

| Member | Type | Meaning |
| --- | --- | --- |
| `target` | `object\|string\|null` | the proxied instance (transparent). On an explicit `Pipeline::call()` it is the callable object when the callable *is* an object (`Closure`, invokable), otherwise `null` — string and array callables are not objects |
| `method` | `?string` | the method name (transparent) or `null` (explicit `Pipeline::call()`) |
| `identity` | `string` | stable label: `Class::method`, `file:line` for closures, or a caller-supplied label |
| `args` | `array` | arguments; **reference elements stay references** across the chain |
| `parameterNames` | `list<string>` | parameter names baked by the proxy; empty for explicit calls |
| `attributes` | `array<string,mixed>` | scratch space shared by all interceptors for this call |
| `advice(class-string)` | `?Advice` | the advice baked in for this call, or `null` |

A typical interceptor:

```php
final class Timed implements InterceptorInterface
{
    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $start = hrtime(true);

        try {
            return $next($invocation);
        } finally {
            $this->record($invocation->identity, hrtime(true) - $start);
        }
    }
}
```

### Argument references

`$invocation->args` holds references when the caller passed them. Reference elements survive the chain in both entry points:

- **Transparent (proxy):** the generated override builds `args` as `[&$target, $n]`; the chain's terminal frame invokes `parent::method(...)` through a by-reference variadic closure, so a `function f(array &$x)` parameter sees the caller's array.
- **Explicit (`Pipeline::call`):** pass references in `$args` (`[&$x]`) and they survive to the wrapped callable.

`$invocation->args` may be reassigned (`$invocation->args = [...]`) or mutated in place; both are visible to downstream interceptors and to the wrapped callable. Reassigning `args` builds a new array — element references are preserved only if you carry them over, exactly as with plain PHP arrays.

## Advice beats constructor defaults

Interceptors ship with constructor defaults so they can be used without attributes. When an advice attribute is present on the method, the attribute's values win:

```php
new RetryInterceptor(defaultTimes: 5);   // constructor default, explicit calls use this

#[Retry(times: 2, backoff: 0)]           // attribute wins on the proxied call
public function flaky(): void { ... }
```

An interceptor that requires configuration and has no meaningful default (the `sailantis/azera-aop` package) throws a `LogicException` naming the missing advice instead of silently doing nothing.

## Explicit AOP (Pipeline)

```php
use Azera\Aop\Pipeline;
use Azera\Aop\RetryInterceptor;

$pipeline = new Pipeline([new RetryInterceptor(defaultTimes: 3)]);
$result   = $pipeline->call(fn() => $service->chargeCard(100));

// Short form
$result = Pipeline::wrap([new RetryInterceptor(defaultTimes: 3)], fn() => $service->chargeCard(100));
```

`Pipeline::call(callable $callable, array $args = [], ?string $identity = null, array $advice = []): mixed`

- `$args` is passed to the callable spread as arguments. Reference elements survive.
- `$identity` overrides the identity string. If omitted, closures are named by `file:line` (one reflection per call — pass `identity:` to skip it); a callable with no reflection story (string, array callable) is named directly.
- `$advice` passes an advice map (`[Transactional::class => new Transactional(...)]`) for interceptors that read advice.

### Attribute configuration on the explicit path

The explicit path has no method to read attributes from, so nothing is picked up automatically. Everything must be passed explicitly:

- `#[Transactional('analytics')]` on a method does nothing here — pass the advice map: `advice: [Transactional::class => new Transactional('analytics')]`. Without it, the interceptor's `defaultRole` constructor argument (`write`) applies.
- `#[Cache(key: 'user_{id}')]`: there are no `parameterNames`, so `{id}` is filled from **string keys** in `$args`. Two consequences: (a) positional args can't supply the name, and the interceptor raises a `LogicException` naming the unresolved placeholder — it never silently shares one cache entry across different arguments; (b) those string keys are also spread into the callable as PHP named arguments, so they must match its parameter names exactly. `['id' => 42]` works for `function (int $id)`; anything else misbinds or throws. Omitting `key` avoids all of it — the key is then one digest of `$identity`, the method and the args.
- Advice passed via `advice:` goes through the very same code as the proxy path (`$invocation->advice(...)`), so the key interpolation is identical; the only difference is where the parameter names come from. On the explicit path, named args are the only source.

### Transactional, explicitly

There is nothing special about transactions on the explicit path — same interceptor, same
`begin/commit/rollback` handling, including savepoint nesting and the failed-COMMIT path.

```php
use Azera\Aop\Pipeline;
use Azera\Aop\TransactionalInterceptor;

$write   = new Pipeline([new TransactionalInterceptor($ctx->dbManager())]);
$billing = new Pipeline([new TransactionalInterceptor($ctx->dbManager(), defaultRole: 'billing')]);
```

```php
// Commits on return, rolls back and re-throws on any Throwable.
$order = $write->call(
    fn() => $orderService->createOrder($data),
    identity: 'OrderService::createOrder',   // skip closure reflection, name it in logs
);
```

```php
// The write connection is the default; name another role through the advice map.
$billing->call(
    fn() => $invoiceService->charge($invoiceId),
    identity: 'InvoiceService::charge',
    advice: [Transactional::class => new Transactional(connection: 'billing')],
);
```

Two things behave exactly as they do through the proxy:

- **Nested calls use savepoints.** An outer advised call that invokes another advised call
  on the same connection opens `begin(true)`, so the inner one is a savepoint; an inner
  failure rolls back only the savepoint and the outer work still commits.
- **A failed COMMIT surfaces its own error** (deferred constraint, deadlock at commit) and
  the connection's nesting counter is reconciled with the driver, so no transaction is left
  dangling and no `"There is no active transaction"` replaces the real cause.

The only difference from the proxy path is where the connection role comes from: the
attribute cannot be read without a method, so either pass `defaultRole:` to the
constructor (applies to every call through that pipeline) or pass an `advice:` map per
call. A role that is not configured still fails loudly with the list of configured roles
rather than silently falling back to the default connection.

Passing `advice:` per call allocates one `Transactional` instance each time; for a hot
path, give the pipeline its own interceptor (the `$billing` above) and pass no advice.

## Transparent AOP (proxy generation)

### How it works

1. Mark a class with `#[Advised]`.
2. Mark methods with advice attributes (`#[Transactional]`, `#[Cache]`, …).
3. Register the matching interceptors (`AppContext::registerInterceptor`).
4. Register the service by class-string so `AppContext::build()` proxies it.

`build()` checks in-process memos and the class pointer first. On a miss, it reads advice
arguments and generates a subclass overriding only advised methods. The proxy is written
under a content-addressed name, or created with `eval()` when disk caching is disabled.

Each override gets its memoized plan from `AopRuntime`, creates an `Invocation`, and calls
the pipeline. The runtime constructs advice from arguments embedded in the proxy; it does
not reflect on attributes. The terminal calls `parent::method(...)` through a per-call
by-reference variadic closure.

Proxies declare no properties, so clones call methods on themselves, instances remain
serializable, and target properties cannot collide. The embedded namespace derives from
the cache directory, keeping interceptor maps separate across factories and stable across
processes.

With disk caching (the `AppContext` default), proxies use the named class
`AzeraAopProxy_<Class>_<digest>`, based on the target, emitted body, and generator version.
Files are atomic and reusable by OPcache. `setAopCacheDir(null)` uses anonymous `eval()`
classes without disk caching.

#### Advice arguments are generated

Generated methods embed advice constructor arguments:

```php
$__plan = \Azera\Aop\AopRuntime::plan(
    namespace: 'factory:/app/temp/aop',
    class: 'App\OrderService',
    method: 'getOrder',
    advice: ['Azera\Aop\Cache' => ['ttl' => 60, 'key' => 'order_{id}']],
    parameterNames: ['id'],
);
```

Attribute changes alter the generated body and digest. Already-loaded classes retain
their metadata.

Named and positional forms are preserved. Values must be stable PHP literals: scalars,
arrays, enum cases, class constants, or `null`. Object arguments are rejected because
`var_export()` may require `__set_state()`.

#### Writes and OPcache

Atomic writes are backdated past `opcache.file_update_protection` for immediate caching.
Changed bodies get new digest paths; only a mismatched file is replaced and invalidated.
Reuse checks `is_file()`. Cache directories use mode `0700`; group/world-writable ones are
rejected.

#### The pointer file

Each class has a `Proxy_<key>.ptr` recording its current body, advice map, and source
signature (`mtime:size`). The signature is a reuse hint, not a key. Missing or invalid
pointers trigger regeneration; pointers also identify live bodies for pruning.

#### Writes are content-addressed; timestamps are only a hint

A body filename is `xxh3(GENERATOR_VERSION, class, emitted body)`. A worker with stale
metadata keeps its digest; a fresh process derives a new one. Classes without a usable
source signature (traits, internal, or `eval`'d classes) always regenerate.

#### What a deploy must do

Source changes are visible only to processes that have not memoised the previous version:

| Memo | Scope | Cleared by |
| --- | --- | --- |
| `ProxyFactory::$proxyClassCache` | factory | `register()`, `setCacheDir()`; context registration replaces the factory |
| `ProxyFactory::$loadedProxies` | process | process restart |
| `AopRuntime::$plans` | process | `clearPlans()` (`registerInterceptor()` calls it) |
| Loaded bytecode | OPcache | `opcache_reset()` / reload when timestamp validation is disabled |

The first two checks precede reflection: ~3 µs for two lookups versus ~140 µs for scanning,
generation, and hashing in the measured setup.

Attribute edits generate a new proxy and plan, but existing services retain their plan.
Replace workers after advice changes. Method-body edits take effect on the next load
through `parent::method(...)`:

```bash
systemctl reload php8.5-fpm     # keeps workers: still holding the old instances
systemctl restart php8.5-fpm    # new workers construct from the new proxy files
php -r 'opcache_reset();'       # or drop opcache.validate_timestamps=0 reloads
```

#### Reclaiming superseded files

Inspect or remove superseded bodies and OPcache entries:

```bash
php azera cache:status                   # counts bodies no pointer names
php azera cache:prune --only=aop         # delete them
php azera cache:prune --older-than=3600  # keep files newer than an hour
php azera cache:clear --only=aop         # wipe the directory unconditionally
```

**Pruning follows pointer files**, removing unreferenced bodies and dangling pointers.
It works from a cold CLI and leaves non-`Proxy_<hex>.php` files untouched. Use
`cache:clear --only=aop` to remove everything, including files for deleted classes.

By default, proxies are stored under `<temp>/azera_aop` — one directory for the whole
host, like PHP's own session files. Two applications sharing it is safe: bodies are
content-addressed and each pointer records the interceptor map and source signature, so
a process that meets another application's pointer regenerates instead of serving the
wrong advice. Pass `setAopCacheDir()` if you would rather keep a deployment separate.

### Enabling AOP

```php
use Azera\Aop\Transactional;
use Azera\Aop\TransactionalInterceptor;

$ctx->setAopCacheDir(dirname(__DIR__) . '/temp/aop');   // null = eval() for development
$ctx->registerInterceptor(Transactional::class, new TransactionalInterceptor($ctx->dbManager()));
```

Register interceptors **before** resolving advised services. Late registration is tolerated —
already-resolved `#[Advised]` instances are evicted and rebuilt on the next resolve, keeping
the registered recipe (class string or factory) so the service comes back the way the
application wired it — but nothing already executing can be retrofitted.

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
        // begin() before, commit() after, rollback() on throwable
    }

    #[Cache(ttl: 60, key: 'order_{id}')]
    public function getOrder(int $id): ?Order
    {
        return Order::find($id);
    }
}
```

### Critical: autowiring vs factory

The service **must** be registered as a class string for the proxy to be generated. `build()` is only reached for class-string definitions:

```php
// Correct: build() generates the proxy
$ctx->set(OrderService::class);

// Wrong: factory returns a plain instance, no proxy
$ctx->set(OrderService::class, fn() => new OrderService(...));
```

Constructor dependencies are resolved automatically during autowiring.

## Built-in advice

| Attribute | Interceptor | Notes |
| --- | --- | --- |
| `#[Transactional]` | `TransactionalInterceptor` | savepoints for nesting; `connection:` selects a role |
| `#[Cache(ttl, key?)]` | `CacheInterceptor` | `{argName}` placeholders; derived key is one `xxh128` digest of the triple |
| `#[Retry(times, backoff)]` | `RetryInterceptor` | `times` is the total attempt count |
| `#[Log(level, logArgs)]` | `LogInterceptor` | logs entry, exit, duration, exception |

Prefixes, package `sailantis/azera-aop`:

| Attribute | Interceptor |
| --- | --- |
| `#[Audit]` | `AuditInterceptor` |
| `#[Authorize]` | `AuthorizeInterceptor` |
| `#[Bulkhead]` | `BulkheadInterceptor` |
| `#[CircuitBreaker]` | `CircuitBreakerInterceptor` |
| `#[Idempotent]` | `IdempotentInterceptor` |
| `#[Throttle]` | `ThrottleInterceptor` |
| `#[Trace]` | `TraceInterceptor` |
| `#[Validate]` | `ValidateInterceptor` |

### #[Transactional]

Wraps the method in a database transaction: `begin()` before, `commit()` after, `rollback()` on any `Throwable` (then the exception is re-thrown). Nested `#[Transactional]` calls use savepoints.

```php
#[Transactional]
public function transfer(int $from, int $to, float $amount): void { ... }

#[Transactional(connection: 'analytics')]
public function recordEvent(Event $e): void { ... }
```

A failed `COMMIT` surfaces its own error; the interceptor does not issue a redundant rollback that would mask it as *"There is no active transaction"*, and the connection's nesting counter is reconciled with the driver.

**Match the connection to the write path.** If a model redirects its writes (`Model::setDefaultWriteRole()` or `#[Connection]`), the advice must name the same role or the transaction will not cover them. `TransactionalInterceptor` can detect a connection left open on another role at runtime (`detectLeakedConnections: true`). The check runs **before** `COMMIT` and rolls the whole unit of work back when it fires, so a retry outside it cannot replay already-committed writes. Connections that were already inside a transaction before the advised method ran (an outer unit of work on another role) are not reported.

**Role resolution.** A role named on the attribute must be configured, or the interceptor throws rather than silently writing to the wrong database. An unnamed role is the interceptor's default intent (`defaultRole: 'write'`), and falls back to the manager's default connection when that role is not configured — so an application with a single default connection needs no extra setup.

### #[Cache]

Caches the return value under a key derived from `(identity, method, args)`, or under a literal template when `key:` is given.

```php
#[Cache(ttl: 300)]                          // derived key
public function getProfile(int $userId): Profile { ... }

#[Cache(ttl: 600, key: 'user_{userId}_profile')]   // literal key
public function loadProfile(int $userId): Profile { ... }
```

The derived key is **one 32-character `xxh128` digest** of `serialize([$identity, $method, $args])`. It is deliberately not human-readable: emitting a readable prefix required sanitizing the identity (`A\B` and `A/B` collapsed to the same `_`), memoizing the result, capping its length and folding long identities into a fallback digest. The flat digest removes all of that and is roughly a third cheaper per call.

`serialize()` carries the boundaries between the three parts, so distinct inputs cannot alias — `('ab', 'c')` vs `('a', 'bc')`, `null` vs `''` method names, `'id' => 7` vs `0 => 7`, and `1` vs `'1'` vs `1.0` all produce different keys.

Placeholders in an explicit `key:` template are resolved against the argument values, and the resolved template is never re-scanned: a value that merely looks like a placeholder (`'ops_{admin}'`) is inserted verbatim. Only placeholders that are actually **in the template** are substituted, and one that no argument supplies raises a `LogicException` naming it.

Every value gets a distinct, type-prefixed, key-safe token, so different arguments cannot collapse onto one entry:

| Argument | Token |
| --- | --- |
| `null`, `true`, `false` | `null`, `true`, `false` |
| int | the integer |
| short, key-safe string | `s_<value>` (`s_user_42`) |
| float | `f_<value>` |
| any other string / array / object | `str_` / `arr_` / `obj_` + a digest |
| unserializable value (closure, resource) | throws |

The prefixes are what keep values of *different* types apart: `42`, `'42'` and `42.0` are three keys, and `'null'` is not `null`. A value the template does not name is never tokenized — it may be an object that cannot be serialized, and failing on it would reject a perfectly resolvable key.

Two consequences worth knowing:

- **Keys are opaque.** `xxh128` is not adversarial-collision resistant, which is fine for arguments the application itself supplies but not for attacker-chosen ones. When arguments are user-controlled and a collision would leak a cached value, pass an explicit `key:` template instead.
- **Changing the key shape invalidates existing entries.** Treat it as a cache version bump across a deploy.

### Not everything can be advised

Proxy advice overrides the method:

- a **`final` method** carrying an advice attribute cannot be overridden — remove `final`, or wrap it;
- a **`final` class** cannot be extended at all — advice on its methods is unresolvable.

Both are reported as a `RuntimeException` naming the class and method when the proxy is first built.

## Writing a custom interceptor

```php
use Azera\Aop\Advice;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Audited extends Advice
{
    public function __construct(public readonly string $action) {}
}

final class AuditInterceptor implements InterceptorInterface
{
    public function __construct(private AuditLog $log) {}

    public function intercept(Invocation $invocation, callable $next): mixed
    {
        $advice = $invocation->advice(Audited::class);   // null in explicit mode unless advice: is passed

        $result = $next($invocation);

        $this->log->record($advice?->action ?? 'unknown', [
            'target' => $invocation->identity,
            'args'   => $invocation->args,
        ]);

        return $result;
    }
}
```

Register it like any built-in:

```php
$ctx->registerInterceptor(Audited::class, new AuditInterceptor($auditLog));

#[Advised]
class OrderService
{
    #[Audited(action: 'order.cancelled')]
    public function cancel(int $id): void { ... }
}
```

`Invocation::$attributes` is the scratch space for inter-interceptor state (timers, attempt counters). Keep it per-call — never stash it on the interceptor instance, which is shared across calls and requests in the same process.

## Performance

Measured with `hrtime`, median of 5 × 100k calls (helpers in `benchmarks/aop-overhead.php`), PHP 8.3.31 (ZTS, xdebug off), one Windows 11 machine. **These are indicative figures for this host, not portable guarantees** — allocators, opcache state and CPU frequency shift them by tens of percent. Reproduce with:

```bash
php -d xdebug.mode=off benchmarks/aop-overhead.php
```

A representative run:

| Scenario | ns/call |
| --- | ---: |
| Raw method call | ~69 |
| `Pipeline`, 0 interceptors (closure hoisted at the call site) | ~222 |
| `Pipeline`, 0 interceptors (closure built per call, as in the loop) | ~228 |
| `Pipeline`, 1 (`Retry`), default identity | ~1064 |
| `Pipeline`, 1 (`Retry`), `identity:` passed | ~1146 |
| `Pipeline`, 2 (`Retry` + `Log`), default identity | ~1984 |
| `Pipeline`, 2 (`Retry` + `Log`), `identity:` passed | ~1997 |
| `Pipeline`, 3 (`Retry` + `Log` + `Cache`, warm) | ~2820 |
| Proxy, 1 (`Retry`) | ~1352 |
| Proxy, 2 (`Retry` + `Log`) | ~2140 |
| Proxy, 3 (`Retry` + `Log` + `Cache`, warm) | ~3056 |

A zero-interceptor pipeline is a plain variadic call: no `Invocation`, no identity
derivation, no chain frame. The ~181 ns row is `$pipeline->call($fn, [7])` against a
long-lived callable, i.e. roughly **+113 ns over a raw call**; the ~229 ns row is the
same code with a freshly allocated closure per iteration, so most of that difference is
the caller's own closure allocation, not the pipeline.

Observations from five consecutive full runs on this machine:

- Passing `identity:` saves ~**80 ns** median by skipping the `ReflectionFunction` for closures (range −12 to +137 ns across runs; the saving is real but small and noisy).
- Proxy vs explicit at 1 interceptor: the proxy was slower in **all five** runs by ~180–250 ns. Part of that gap is the per-call terminal closure the proxy allocates (it replaced an instance-cached one that could not be cloned or serialized — see the proxy section above); the rest is the generated method's extra frame and `Invocation` construction.
- Proxy vs explicit at 2 interceptors: mixed and within noise (proxy-minus-explicit ranged from −137 to +160 ns across runs).
- Proxy vs explicit at 3 interceptors (warm cache): the proxy was faster in 4 of 5 runs by 67–562 ns. Both paths short-circuit inside `CacheInterceptor`, so treat this as dispatch+cache-hit cost, not "proxy is universally faster".
- The proxy's structural advantages (no identity derivation, no advice lookup, no interceptor resolution) are worth the most at longer chains, where the per-call savings compound; at one interceptor the generated method's extra frame, per-call terminal closure and `Invocation` construction cancel them out.

Do not optimize against these numbers. The meaningful costs in a real app are the interceptor bodies (a database round trip is microseconds to milliseconds); interceptor dispatch is noise next to them.

Registration-state costs:

| State | Cost |
| --- | --- |
| No interceptors registered | zero — `build()` returns a plain instance |
| Interceptors registered, class not `#[Advised]` | one memoised lookup after the first construction — the parent-chain check runs once per class, not once per instance |
| `#[Advised]` but no advised methods | one attribute scan, then a plain instance |
| `#[Advised]` with advised methods | generation once per (class, method) shape; thereafter an OPcache'd file or eval'd class |

Non-advised methods on a proxied class are not overridden and cost nothing.
