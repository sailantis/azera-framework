# Interface: InterceptorInterface

**Full name:** [Azera\Aop\InterceptorInterface](../../src/Aop/InterceptorInterface.php)

Contract for interceptors that wrap advised methods.

An interceptor receives the target object, the reflection method being
intercepted, the method arguments, and a `$next` callable that invokes
the next interceptor in the chain (or the actual method if this is the
last interceptor). Interceptors can:

- Run code before calling `$next()`
- Modify the arguments before calling `$next()`
- Short-circuit by returning a value without calling `$next()`
- Run code after `$next()` returns (post-processing)
- Catch/transform exceptions thrown by `$next()`

Example:
```php
class TransactionalInterceptor implements InterceptorInterface
{
    public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed
    {
        $db->begin();
        try {
            $result = $next($args);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
```

## Public methods

### intercept() · <small>[🗎](../../src/Aop/InterceptorInterface.php#L52)</small>

`public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed`

Intercept a method invocation.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$target` | object | - | The proxy-wrapped target object. |
| `$method` | ReflectionMethod | - | The reflection method being intercepted. |
| `$args` | array | - | The method arguments (may be modified). |
| `$next` | callable | - | Callable that invokes the next handler.<br>Receives the (possibly modified) args array and returns the method result. |

**Return value**

- Type: `mixed`
- Description: The method result (or a replacement value).



---

[Back to the Index ⤴](README.md)
