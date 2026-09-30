# Class: Pipeline

**Full name:** [Azera\Aop\Pipeline](../../src/Aop/Pipeline.php)

A simple interceptor pipeline that wraps a callable.

This is the explicit, no-proxy alternative to AOP attributes. It lets
users compose interceptors around any callable without generating proxy
classes. The same [`InterceptorInterface`](Aop_InterceptorInterface.md) implementations that work
with the proxy AOP also work here — just pass them as an array.

The innermost handler calls the target callable. Each interceptor wraps
the next, exactly like HTTP middleware or the Dispatcher's middleware
pipeline.

Example:
```php
$result = $ctx->pipeline()
    ->through([new RetryInterceptor(3), new LogInterceptor($logger)])
    ->call(fn() => $service->chargeCard(100));
```

Or the short form:
```php
$result = Pipeline::wrap(
    [new RetryInterceptor(3), new LogInterceptor($logger)],
    fn() => $service->chargeCard(100),
);
```

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Pipeline.php#L42)</small>

`public function __construct(array $interceptors = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | `[]` |  |

**Return value**

- Type: `mixed`


---

### through() · <small>[🗎](../../src/Aop/Pipeline.php#L60)</small>

`public function through(array $interceptors): static`

Add interceptors to the pipeline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | - |  |

**Return value**

- Type: `static`


---

### call() · <small>[🗎](../../src/Aop/Pipeline.php#L84)</small>

`public function call(callable $callable, array $args = []): mixed`

Run the pipeline around a callable.

Each interceptor wraps the next. The innermost call invokes `$callable`
with the provided arguments.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$callable` | callable | - | The target callable to execute. |
| `$args` | array | `[]` | Arguments to pass to the callable. |

**Return value**

- Type: `mixed`
- Description: The callable's return value.

**Throws**

- Throwable  If the callable (or any interceptor) throws.


---

### wrap() · <small>[🗎](../../src/Aop/Pipeline.php#L108)</small>

`public static function wrap(array $interceptors, callable $callable, array $args = []): mixed`

Static shortcut: wrap a callable with interceptors and call it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$interceptors` | array | - |  |
| `$callable` | callable | - |  |
| `$args` | array | `[]` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
