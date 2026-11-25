# Upgrading to the 0.7 AOP redesign

The AOP subsystem was rebuilt around a single invocation object. The shape in 0.6 and earlier was:

- `InterceptorInterface::intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed`
- `Pipeline::through([...])->call($callable, $args)` (a mutable fluent pipeline)
- `PipelineHandler` — an `@internal` stand-in class the pipeline reflected over, so interceptors written for the proxy path had something to receive as a `ReflectionMethod`
- a per-call `new ReflectionMethod(parent::class, $method)` inside every generated proxy override

All of that is replaced. There is one contract, one chain implementation, and one generated-code path.

## Breaking changes

### `InterceptorInterface` signature

```php
// before
public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed;

// after
public function intercept(Invocation $invocation, callable $next): mixed;
```

`$next` now receives the `Invocation`, not `$args`. Whatever used to be read off the
`ReflectionMethod` is on `Invocation` instead:

| Before | After |
| --- | --- |
| `$method->getName()` | `$invocation->method` (`null` on the explicit path) |
| `$method->getDeclaringClass()->getName()` | `$invocation->identity` (or `$invocation->target::class`) |
| `$method->getAttributes(X::class)` + `newInstance()` | `$invocation->advice(X::class)` |
| `$method->getParameters()` / `getParameters()[$i]->getName()` | `$invocation->parameterNames` (proxy-baked) |
| `$next($args)` | `$next($invocation)` |

`$target` used to be typed `object`; it is now `object|string|null` — closures and string callables on the explicit path have no instance. Interceptors that assumed an object must handle the other cases or bail out.

### `$next` now takes the invocation, not the args

```php
// before
public function intercept(object $target, ReflectionMethod $m, array $args, callable $next): mixed
{
    return $next($args);
}

// after
public function intercept(Invocation $invocation, callable $next): mixed
{
    return $next($invocation);
}
```

### `Pipeline::through()` is deprecated; the chain is precomposed

`Pipeline` composes its interceptor chain **once, in the constructor**. Prefer passing the whole list to the constructor:

```php
// before
$pipeline = (new Pipeline([$retry]))->through([$log]);

// after (preferred)
$pipeline = new Pipeline([$retry, $log]);
```

`through()` is retained as a **deprecated, immutable alias**: it returns a new `Pipeline` whose chain is the receiver's interceptors followed by the supplied ones, so the 0.6 call sites above keep working unchanged. It no longer mutates the receiver — reuse the returned instance:

```php
$pipeline = (new Pipeline([$retry]))->through([$log]);   // works, but capture the result
```

`Pipeline::call()` gained optional `identity:` and `advice:` parameters. `Pipeline::wrap()` also gained `?string $identity = null` (it previously took only `$interceptors, $callable, $args`). `Pipeline::handle(Invocation)` is new; there was no equivalent before.

### Advice wins over constructor defaults (and is now uniform)

Both paths now resolve configuration the same way — advice when the invocation carries it, constructor values otherwise:

```php
new RetryInterceptor(defaultTimes: 5);   // no advice present: 5 attempts

#[Retry(times: 2, backoff: 0)]           // advice present: 2 attempts, not 5
public function flaky(): void { ... }
```

What actually changed: before, the attribute was read *only* through a `ReflectionMethod`, so the proxy path saw it but an explicit pipeline call could never supply advice at all — `Pipeline::call()` had no `advice` parameter. Now `$invocation->advice(X::class)` works on both paths, and `Pipeline::call()` accepts an explicit `advice:` map. The precedence rule itself (attribute wins) is unchanged; what is new is that the explicit path can opt in.

### `ProxyFactory::setCurrent()` / `ProxyFactory::current()` removed

The process-wide "current factory" was how generated proxies found their interceptors at call time. They no longer do. The generated override hard-codes the advice *class names* and the factory's interceptor **namespace** as literals, then asks `AopRuntime::plan(<namespace>, class, method, [Advice::class, ...])` for the plan; that call builds the `Pipeline` and the advice instances once per `(class, method)` and memoizes them for the process. So the registry is still involved — but once per method, not once per call. `ProxyFactory::getInterceptor()` remains for diagnostics only.

The namespace is derived from the proxy cache directory (`ProxyFactory::namespace()`), which is why generated proxies must be rebuilt when the directory changes — `setCacheDir()` does that automatically. It is what lets two factories in one process — two `AppContext` instances, a test harness beside the application — each keep their own interceptors: previously the second publication overwrote the first, and a proxy generated by the first began invoking the second factory's interceptor.

`AppContext` still calls `registerInterceptor()` and still wires the factory. A hand-built `ProxyFactory` works only if its `register()` calls publish to `AopRuntime` — which `ProxyFactory::register()` does, and `AppContext` additionally publishes the fully resolved map (after lazy factories are invoked) so generated classes see the same interceptors the factory would use. `AppContext::registerInterceptor()` also clears the memoized plans, so a proxy invoked before the factory is rebuilt picks up the new interceptor.

### Generated proxies changed shape

The generated class emits one `Invocation` per call plus a by-reference variadic terminal closure (`fn(&...$a) => parent::method(...$a)`), instead of a per-call `new ReflectionMethod(parent::class, $method)` and per-call chain construction. Consequences:

- Reference parameters survive end to end — the proxy terminal and the chain both spread by reference. Both `&$arg` parameters and `&...$args` variadics are carried as references. (The 0.6 generator already carried `&$arg` into the args array; it wrongly assumed variadics could not be by-reference and dropped them.)
- `parameterNames` is baked into the invocation, so `#[Cache(key: 'order_{id}')]` works on the proxied path with no per-call reflection.
- `void` methods are still emitted as a statement, not a `return` (unchanged from 0.6).
- The proxy declares **no properties**. The terminal closure is allocated per call rather than cached on the instance, because a cached one was bound to the instance that first called the method: a `clone` then inherited a terminal bound to the original, the proxy stopped being serializable once the property held a `Closure`, and the generated `__aopTerminal_<method>` property name could collide with a matching property on the target class (a fatal "access level must not be reduced" error). Measured cost of the per-call closure is ~0.2 µs per advised call.

`GENERATOR_VERSION` was bumped, so stale proxy cache files are ignored automatically; no manual cache flush is needed.

### Explicit calls carry no advice by default

`Pipeline::call()` on a plain callable has no attribute to read. Interceptors that read advice receive `null` and use their constructor defaults. To pass advice explicitly:

```php
$pipeline->call(
    $callable,
    args: [$id],
    identity: 'orders.charge',
    advice: [Retry::class => new Retry(times: 3, backoff: 100)],
);
```

Note the `Cache` key template is only resolved against **parameter names**. On the explicit path those come from the args array's keys (there is no way to pass a separate `parameterNames` list to `Pipeline::call()`), so pass named arguments (`['id' => $id]`). A placeholder that cannot be resolved raises a `LogicException` naming it, rather than silently collapsing every argument onto one cache entry.

## New in 0.7

- `Azera\Aop\Invocation` — the single interception context.
- `Azera\Aop\AopRuntime::plan()` — memoized `(namespace, class, method) → plan` registry used by generated proxies. `@internal`.
- `Azera\Aop\AopRuntime::setInterceptors()` / `setInterceptor()` / `clearPlans()` — per-namespace registry maintenance. `@internal`.
- `Azera\Aop\AopPlan` — the plan object (pipeline + advice map + parameter names), in its own `src/Aop/AopPlan.php` so PSR-4 can resolve it directly. `@internal`.
- `ProxyFactory::namespace()` — the interceptor namespace this factory publishes under, derived from its cache directory. `@internal`.
- `Pipeline::ATTR_CALLABLE` — the attribute key on `Invocation::$attributes` under which the wrapped callable is stored (`'azera.aop.callable'`). `@internal`; generated proxies and `Pipeline::call()` use it to hand the terminal callable to the chain.
- `Azera\Db\DatabaseManager::peek()` — read a role's already-instantiated connection without triggering its factory. Used by `TransactionalInterceptor` to detect connections left inside a transaction.
- `docs/16-AOP.md` — rewritten for the new model; includes measured performance figures.

## Not changed

- `AppContext::registerInterceptor()` — same signature, same semantics (late registration still evicts cached `#[Advised]` definitions).
- `#[Advised]` on a class, advice attributes on methods, `Advised` discovery, and the proxy cache directory behaviour (digest naming, atomic write, `assertSafeCacheDir`) are unchanged.
- The built-in interceptors' existing constructor arguments are unchanged, but each gained new **optional** parameters appended after them, so existing positional construction keeps working: `CacheInterceptor` gained `$defaultTtl`, `LogInterceptor` gained `$defaultLevel` and `$logArgs`, `TransactionalInterceptor` gained `$defaultRole` and `$detectLeakedConnections`. These exist so the same class is usable on the explicit path, where no advice is present. Subclasses that declare their own `__construct` and call `parent::__construct(...)` are unaffected; subclasses that relied on the exact parameter list should be reviewed.

## If you have written custom interceptors

Porting checklist:

1. Change the signature to `intercept(Invocation $invocation, callable $next): mixed`.
2. Replace `ReflectionMethod` reads with `$invocation->method` / `$invocation->parameterNames` / `$invocation->advice()`.
3. Replace `$next($args)` with `$next($invocation)`.
4. If you need the class name, use `$invocation->target::class` (the proxied instance) or `$invocation->identity`.
5. Drop any cached `ReflectionMethod` keyed by method name — it is per-process per-method state that the proxy now owns.
