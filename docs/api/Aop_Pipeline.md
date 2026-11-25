# Class: Pipeline

**Full name:** [Azera\Aop\Pipeline](../../src/Aop/Pipeline.php)

The explicit AOP entry point: wrap a callable in a chain of interceptors.

A Pipeline is immutable and its chain is composed **once**, in the constructor,
as a right fold over the interceptors. `handle()` performs no closure allocation:
every frame of the chain is built ahead of time and captures only the interceptor
instance and the next frame. `call()` short-circuits to a plain variadic call when
no interceptors are registered.

Generated proxies use the same mechanism: advice is resolved once per process by
[`AopRuntime`](Aop_AopRuntime.md), which memoizes one precomposed Pipeline per advised (class, method).

Example:
```php
$pipeline = new Pipeline([new RetryInterceptor()]);
$result   = $pipeline->call(fn() => $service->chargeCard(100));

// Short form
$result = Pipeline::wrap([new RetryInterceptor()], fn() => $service->chargeCard(100));
```

Explicit calls carry no advice attributes. Interceptors that support attribute
configuration fall back to their constructor defaults here; use the transparent
(attribute + proxy) layer when the advice must be declared on the method.

## Public Constants

- **ATTR_CALLABLE** = `'azera.aop.callable'`

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Pipeline.php#L55)</small>

`public function __construct(array $interceptors = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | `[]` | Outermost first. |

**Return value**

- Type: `mixed`


---

### interceptors() · <small>[🗎](../../src/Aop/Pipeline.php#L96)</small>

`public function interceptors(): array`

**Return value**

- Type: `array`


---

### through() · <small>[🗎](../../src/Aop/Pipeline.php#L116)</small>

`public function through(array $interceptors): static`

Add interceptors, returning a new pipeline.

`Pipeline` is immutable and composes its chain in the constructor, so this
cannot mutate the receiver: it returns a new instance whose chain is the
receiver's interceptors followed by `$interceptors` — the same ordering the
old mutable fluent method produced.

Retained for 0.6 callers that wrote
`(new Pipeline([$a]))->through([$b])->call(...)`. Prefer passing the whole
list to the constructor.

**Deprecated**: Pass every interceptor to the constructor instead.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | - | Appended after the existing ones. |

**Return value**

- Type: `static`


---

### handle() · <small>[🗎](../../src/Aop/Pipeline.php#L126)</small>

`public function handle(Azera\Aop\Invocation $invocation): mixed`

Run an already-built invocation through the chain.

Used by generated proxies, whose generated code builds the Invocation inline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$invocation` | [Invocation](Aop_Invocation.md) | - |  |

**Return value**

- Type: `mixed`


---

### call() · <small>[🗎](../../src/Aop/Pipeline.php#L145)</small>

`public function call(callable $callable, array $args = [], string|null $identity = null, array $advice = []): mixed`

Invoke `$callable` through the chain.

With no interceptors registered nothing can observe an Invocation, an identity
or an advice map, so this short-circuits to a plain variadic call: no Invocation
allocation, no attribute-bag write, no chain frame, no reflection. Call sites
that take an interceptor list from configuration pay only for the call itself.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$callable` | callable | - |  |
| `$args` | array | `[]` | Positional arguments. |
| `$identity` | string\|null | `null` | Stable name for the unit. Supply it to skip<br>derivation for closures. |
| `$advice` | array | `[]` | Advice to expose on the invocation. |

**Return value**

- Type: `mixed`


---

### wrap() · <small>[🗎](../../src/Aop/Pipeline.php#L169)</small>

`public static function wrap(array $interceptors, callable $callable, array $args = [], string|null $identity = null): mixed`

Static shortcut: wrap a callable with interceptors and call it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | - |  |
| `$callable` | callable | - |  |
| `$args` | array | `[]` |  |
| `$identity` | string\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
