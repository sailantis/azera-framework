# Class: ProxyFactory

**Full name:** [Azera\Aop\ProxyFactory](../../src/Aop/ProxyFactory.php)

Creates proxy classes that wrap advised methods with interceptor chains.

The proxy is an anonymous class that extends the target class. It IS
the instance — no separate target object, no state mismatch. Overridden
methods call `parent::method()` to reach the original implementation.

Methods without advice attributes are NOT overridden — they call the
parent implementation directly with zero overhead.

In-process caching: `buildProxyClass()` memoises per target class, and the
proxy class is looked up with `class_exists(..., false)` before any file I/O.

On-disk caching (when a cache dir is set): the proxy is written once per
(target class, generated body, generator version) and `require`d thereafter.
The class name is DETERMINISTIC, which is what lets OPcache serve the file
instead of recompiling it on every cold request — see `loadProxyClass()` for
why a stable name is safe here and how a planted file is rejected.

Cost when no interceptors are registered: zero (AppContext::build() never
calls ProxyFactory).
Cost when interceptors are registered but class has no #[Advised]: zero
(AppContext::build() checks the class-level attribute first).
Cost when class has #[Advised] but no advised methods: one-time
ReflectionMethod scan, then the raw target is returned — no proxy.

## Public methods

### register() · <small>[🗎](../../src/Aop/ProxyFactory.php#L91)</small>

`public function register(string $adviceClass, Azera\Aop\InterceptorInterface $interceptor): void`

Register the interceptor for one advice class, replacing any previous one.

The resolved map is published to [`AopRuntime`](Aop_AopRuntime.md) so generated proxies can
resolve their precomposed chains without holding a factory reference.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$adviceClass` | string | - |  |
| `$interceptor` | [InterceptorInterface](Aop_InterceptorInterface.md) | - |  |

**Return value**

- Type: `void`


---

### namespace() · <small>[🗎](../../src/Aop/ProxyFactory.php#L104)</small>

`public function namespace(): string`

The namespace this factory publishes under.

Lazily derived so `register()` before `setCacheDir()` still lands somewhere
sane, and so `setCacheDir()` can move a factory to its final namespace.
`AppContext::proxyFactory()` sets the directory first, then registers.

**Return value**

- Type: `string`


---

### setCacheDir() · <small>[🗎](../../src/Aop/ProxyFactory.php#L117)</small>

`public function setCacheDir(string|null $dir): void`

Set the cache directory for file-based proxy generation.

When set, proxy classes are written to disk as PHP files and `require`d,
allowing OPcache to cache them. When null (default), `eval()` is used.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string\|null | - | Cache directory, or null to use eval(). |

**Return value**

- Type: `void`


---

### getCacheDir() · <small>[🗎](../../src/Aop/ProxyFactory.php#L132)</small>

`public function getCacheDir(): string|null`

**Return value**

- Type: `string`|`null`


---

### hasInterceptors() · <small>[🗎](../../src/Aop/ProxyFactory.php#L137)</small>

`public function hasInterceptors(): bool`

**Return value**

- Type: `bool`


---

### proxyPath() · <small>[🗎](../../src/Aop/ProxyFactory.php#L152)</small>

`public function proxyPath(ReflectionClass $ref): string|null`

The cache file this factory would use for $ref, or null when nothing would
be written (no cache dir, or the class has no advised methods).

Public so deployment tooling can warm or prune the proxy cache without
re-deriving the naming scheme, and so tests can plant a file at the exact
path the loader will look at.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ref` | ReflectionClass | - |  |

**Return value**

- Type: `string`|`null`
- Description: Absolute path, or null.


---

### buildProxyClass() · <small>[🗎](../../src/Aop/ProxyFactory.php#L178)</small>

`public function buildProxyClass(ReflectionClass $ref): string|null`

Build the proxy class for a target class.

Returns the proxy class name (a class extending the target), or null
if no methods need interception. In file-based mode, the class is
written to disk and `require`d — OPcache caches it. In eval mode
(development), an anonymous class is created inline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ref` | ReflectionClass | - |  |

**Return value**

- Type: `string`|`null`


---

### getInterceptor() · <small>[🗎](../../src/Aop/ProxyFactory.php#L697)</small>

`public function getInterceptor(string $adviceClass): Azera\Aop\InterceptorInterface`

The interceptor registered for one advice class.

Exposed for diagnostics and tests; generated proxies resolve their plan
through [`AopRuntime`](Aop_AopRuntime.md) instead.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$adviceClass` | string | - |  |

**Return value**

- Type: [InterceptorInterface](Aop_InterceptorInterface.md)



---

[Back to the Index ⤴](README.md)
