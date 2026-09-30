# Class: ProxyFactory

**Full name:** [Azera\Aop\ProxyFactory](../../src/Aop/ProxyFactory.php)

Creates proxy classes that wrap advised methods with interceptor chains.

The proxy is an anonymous class that extends the target class. It IS
the instance — no separate target object, no state mismatch. Overridden
methods call `parent::method()` to reach the original implementation.

Methods without advice attributes are NOT overridden — they call the
parent implementation directly with zero overhead.

Proxy class names are cached in an array keyed by class name, so repeated
`build()` calls for the same class reuse the same proxy class.

Cost when no interceptors are registered: zero (AppContext::build() never
calls ProxyFactory).
Cost when interceptors are registered but class has no #[Advised]: zero
(AppContext::build() checks the class-level attribute first).
Cost when class has #[Advised] but no advised methods: one-time
ReflectionMethod scan, then the raw target is returned — no proxy.

## Public methods

### register() · <small>[🗎](../../src/Aop/ProxyFactory.php#L42)</small>

`public function register(string $adviceClass, Azera\Aop\InterceptorInterface $interceptor): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$adviceClass` | string | - |  |
| `$interceptor` | [InterceptorInterface](Aop_InterceptorInterface.md) | - |  |

**Return value**

- Type: `void`


---

### setCacheDir() · <small>[🗎](../../src/Aop/ProxyFactory.php#L55)</small>

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

### getCacheDir() · <small>[🗎](../../src/Aop/ProxyFactory.php#L60)</small>

`public function getCacheDir(): string|null`

**Return value**

- Type: `string`|`null`


---

### hasInterceptors() · <small>[🗎](../../src/Aop/ProxyFactory.php#L65)</small>

`public function hasInterceptors(): bool`

**Return value**

- Type: `bool`


---

### buildProxyClass() · <small>[🗎](../../src/Aop/ProxyFactory.php#L80)</small>

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

### setCurrent() · <small>[🗎](../../src/Aop/ProxyFactory.php#L380)</small>

`public static function setCurrent(Azera\Aop\ProxyFactory|null $factory): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$factory` | [ProxyFactory](Aop_ProxyFactory.md)\|null | - |  |

**Return value**

- Type: `void`


---

### current() · <small>[🗎](../../src/Aop/ProxyFactory.php#L385)</small>

`public static function current(): Azera\Aop\ProxyFactory|null`

**Return value**

- Type: [ProxyFactory](Aop_ProxyFactory.md)|`null`


---

### getInterceptor() · <small>[🗎](../../src/Aop/ProxyFactory.php#L390)</small>

`public function getInterceptor(string $adviceClass): Azera\Aop\InterceptorInterface`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$adviceClass` | string | - |  |

**Return value**

- Type: [InterceptorInterface](Aop_InterceptorInterface.md)



---

[Back to the Index ⤴](README.md)
