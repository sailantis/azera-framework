# Class: CacheInterceptor

**Full name:** [Azera\Aop\CacheInterceptor](../../src/Aop/CacheInterceptor.php)

Caches the return value of methods marked with [`Cache`](Aop_Cache.md).

Key resolution:
1. If the advice carries a `key` template, `{argName}` placeholders are filled from the
   invocation's named arguments (transparent mode only — explicit calls are positional).
2. Otherwise a key is derived from a class/identity name, the method name and a hash of
   the arguments.

Explicit (pipeline) calls have no advice and no argument names; they always use the
derived key and the `defaultTtl` constructor argument.

## Public methods

### __construct() · <small>[🗎](../../src/Aop/CacheInterceptor.php#L21)</small>

`public function __construct(Psr\SimpleCache\CacheInterface $cache, int|null $defaultTtl = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$cache` | Psr\SimpleCache\CacheInterface | - |  |
| `$defaultTtl` | int\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### intercept() · <small>[🗎](../../src/Aop/CacheInterceptor.php#L26)</small>

`public function intercept(Azera\Aop\Invocation $invocation, callable $next): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$invocation` | [Invocation](Aop_Invocation.md) | - |  |
| `$next` | callable | - |  |

**Return value**

- Type: `mixed`


---

### keyFor() · <small>[🗎](../../src/Aop/CacheInterceptor.php#L54)</small>

`public static function keyFor(string $className, string|null $methodName, array $args): string`

Build the default cache key for an identity/method/args triple.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$className` | string | - | Identity of the unit (class, `file:line`, or caller-supplied label). |
| `$methodName` | string\|null | - | Method name, or null for explicit calls. |
| `$args` | array | - | Arguments. |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
