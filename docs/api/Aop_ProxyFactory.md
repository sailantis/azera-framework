# Class: ProxyFactory

**Full name:** [Azera\Aop\ProxyFactory](../../src/Aop/ProxyFactory.php)

Builds proxies that run advised methods through interceptor pipelines.

A proxy extends the target and is the service instance. Overridden methods
delegate to `parent::method()`; unadvised methods remain inherited.

`buildProxyClass()` memoizes by namespace and target class before reflection,
then checks for a loaded class before file I/O. Disk caching uses content-addressed
bodies and per-class pointers; a matching source signature skips generation.
Deterministic class names allow OPcache reuse (see `loadCachedProxyClass()`).

With no interceptors registered, no proxy is built. An unadvised class
costs one attribute check; an advised class with no advised methods costs one
reflection scan and returns the raw target.

## Public methods

### register() · <small>[🗎](../../src/Aop/ProxyFactory.php#L66)</small>

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

### namespaceFor() · <small>[🗎](../../src/Aop/ProxyFactory.php#L87)</small>

`public static function namespaceFor(string|null $dir): string`

The namespace a factory publishing from `$dir` will use.

Static and single-sourced: the namespace is baked into generated source and
keys the pointer files, so a caller reasoning about a directory it has no
factory for (a maintenance command) must derive exactly the same string.
Normalisation lives here, not in each caller.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string\|null | - | Cache directory, or null for `eval()`. |

**Return value**

- Type: `string`


---

### namespace() · <small>[🗎](../../src/Aop/ProxyFactory.php#L99)</small>

`public function namespace(): string`

The namespace this factory publishes under.

Lazily derived so `register()` before `setCacheDir()` still lands somewhere
sane, and so `setCacheDir()` can move a factory to its final namespace.
`AppContext::proxyFactory()` sets the directory first, then registers.

**Return value**

- Type: `string`


---

### setCacheDir() · <small>[🗎](../../src/Aop/ProxyFactory.php#L112)</small>

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

### getCacheDir() · <small>[🗎](../../src/Aop/ProxyFactory.php#L125)</small>

`public function getCacheDir(): string|null`

**Return value**

- Type: `string`|`null`


---

### hasInterceptors() · <small>[🗎](../../src/Aop/ProxyFactory.php#L130)</small>

`public function hasInterceptors(): bool`

**Return value**

- Type: `bool`


---

### proxyPath() · <small>[🗎](../../src/Aop/ProxyFactory.php#L141)</small>

`public function proxyPath(ReflectionClass $ref): string|null`

Return the proxy file path, or null when disk caching is disabled or no
methods are advised. Used by tooling to locate or warm the exact cache file.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ref` | ReflectionClass | - |  |

**Return value**

- Type: `string`|`null`
- Description: Absolute path, or null.


---

### pointerFileFor() · <small>[🗎](../../src/Aop/ProxyFactory.php#L162)</small>

`public function pointerFileFor(ReflectionClass $ref): string|null`

Return the target class's pointer file, or null without a cache directory.

Each class keeps one pointer until `self::prune()` removes it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ref` | ReflectionClass | - |  |

**Return value**

- Type: `string`|`null`


---

### buildProxyClass() · <small>[🗎](../../src/Aop/ProxyFactory.php#L175)</small>

`public function buildProxyClass(ReflectionClass $ref): string|null`

Build a proxy class, or return null if no methods need interception.

Disk-backed proxies are required from file for OPcache; eval mode creates
an anonymous class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ref` | ReflectionClass | - |  |

**Return value**

- Type: `string`|`null`


---

### prune() · <small>[🗎](../../src/Aop/ProxyFactory.php#L525)</small>

`public function prune(int $olderThanSeconds = 0): array`

Remove unreferenced proxy bodies and dangling pointers.

All pointers in the directory define the live set, so pruning works from a
cold CLI and across applications. Only `Proxy_<hex>.php` files are candidates;
invalidate OPcache before deleting bodies. Keep uncertain files.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$olderThanSeconds` | int | `0` | Skip files modified more recently than this. |

**Return value**

- Type: `array`


---

### getInterceptor() · <small>[🗎](../../src/Aop/ProxyFactory.php#L1070)</small>

`public function getInterceptor(string $adviceClass): Azera\Aop\InterceptorInterface`

Return the interceptor registered for an advice class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$adviceClass` | string | - |  |

**Return value**

- Type: [InterceptorInterface](Aop_InterceptorInterface.md)



---

[Back to the Index ⤴](README.md)
