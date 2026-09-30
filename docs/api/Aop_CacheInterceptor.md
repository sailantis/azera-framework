# Class: CacheInterceptor

**Full name:** [Azera\Aop\CacheInterceptor](../../src/Aop/CacheInterceptor.php)

Intercepts methods marked with [`Cache`](Aop_Cache.md) and caches their return values.

Cache key resolution:
1. If a custom key template is provided, interpolate `{argName}` placeholders
   with the method arguments.
2. Otherwise, build a key from the class name, method name, and a hash
   of the arguments.

On a cache hit, the method is NOT executed — the cached value is returned.
On a miss, the method executes and the result is stored with the given TTL.

## Public methods

### __construct() · <small>[🗎](../../src/Aop/CacheInterceptor.php#L28)</small>

`public function __construct(Psr\SimpleCache\CacheInterface $cache): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$cache` | Psr\SimpleCache\CacheInterface | - |  |

**Return value**

- Type: `mixed`


---

### intercept() · <small>[🗎](../../src/Aop/CacheInterceptor.php#L32)</small>

`public function intercept(object $target, ReflectionMethod $method, array $args, callable $next): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$target` | object | - |  |
| `$method` | ReflectionMethod | - |  |
| `$args` | array | - |  |
| `$next` | callable | - |  |

**Return value**

- Type: `mixed`


---

### keyFor() · <small>[🗎](../../src/Aop/CacheInterceptor.php#L88)</small>

`public static function keyFor(string $className, string $methodName, array $args): string`

Build the default cache key for a class/method/args triple.

The declaring class name is sanitized because anonymous classes report
names like "Pipeline.php:150$5" (Windows) or, on Linux, the full
absolute declaring path — `getShortName()` has no backslash to split on
there, so the path leaks in. That path is both invalid as a cache key
and arbitrarily long, so an over-long key is folded into a fixed-length
digest. It is never truncated: that would drop the args hash and make
different arguments collide onto a single entry.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$className` | string | - | Declaring class name (may be path-derived). |
| `$methodName` | string | - | Method name. |
| `$args` | array | - | Call arguments. |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
