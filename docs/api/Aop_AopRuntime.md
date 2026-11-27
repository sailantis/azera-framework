# Class: AopRuntime

**Full name:** [Azera\Aop\AopRuntime](../../src/Aop/AopRuntime.php)

Process-wide registry for generated proxies and [`ProxyFactory`](Aop_ProxyFactory.md).

Disk-cached proxies may load in another process, so their source includes advice
arguments and a namespace, but not interceptor instances. On first invocation, this
registry builds and memoizes a plan by namespace, class, and method using the
published interceptor map.

`ProxyFactory` bakes advice arguments into the generated body, so attribute changes
alter the proxy digest. This runtime does not reflect on advice attributes. Namespaces
derive from the cache directory to isolate factories and remain stable across processes.

Standalone callers must publish interceptors via `self::setInterceptors()` or
`ProxyFactory::publish()` before invoking a generated proxy.

## Public methods

### setInterceptors() · <small>[🗎](../../src/Aop/AopRuntime.php#L38)</small>

`public static function setInterceptors(string $namespace, array $interceptors): void`

Replace every interceptor for one namespace.

Plans for that namespace are discarded: they captured the interceptor
instances that were registered when they were built.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - |  |
| `$interceptors` | array | - |  |

**Return value**

- Type: `void`


---

### setInterceptor() · <small>[🗎](../../src/Aop/AopRuntime.php#L49)</small>

`public static function setInterceptor(string $namespace, string $adviceClass, Azera\Aop\InterceptorInterface $interceptor): void`

Register (or replace) one interceptor for a namespace without touching the rest.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - |  |
| `$adviceClass` | string | - |  |
| `$interceptor` | [InterceptorInterface](Aop_InterceptorInterface.md) | - |  |

**Return value**

- Type: `void`


---

### clearPlans() · <small>[🗎](../../src/Aop/AopRuntime.php#L66)</small>

`public static function clearPlans(string $namespace): void`

Forget every memoized plan for one namespace.

Called when the interceptor map changes: a plan captures the interceptor
instances that were registered when it was built, so a plan that survived a
re-registration would keep invoking the old interceptor for an existing
advised method.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - |  |

**Return value**

- Type: `void`


---

### plan() · <small>[🗎](../../src/Aop/AopRuntime.php#L81)</small>

`public static function plan(string $namespace, string $class, string $method, array $advice = [], array $parameterNames = []): Azera\Aop\AopPlan`

Return the memoized plan for a method in a namespace.

The generated proxy supplies advice arguments and parameter names read at
build time, so no reflection is needed. Both are part of the generated source
and digest; changes produce a new proxy.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - |  |
| `$class` | string | - |  |
| `$method` | string | - |  |
| `$advice` | array | `[]` | Advice arguments, keyed by class. |
| `$parameterNames` | array | `[]` | Declared parameter names, in order. |

**Return value**

- Type: [AopPlan](Aop_AopPlan.md)



---

[Back to the Index ⤴](README.md)
