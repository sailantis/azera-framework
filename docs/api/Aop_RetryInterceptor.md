# Class: RetryInterceptor

**Full name:** [Azera\Aop\RetryInterceptor](../../src/Aop/RetryInterceptor.php)

Intercepts methods marked with [`Retry`](Aop_Retry.md) and retries them on failure.

Retries up to `$times` attempts (including the first). Between retries,
sleeps for `$backoff` milliseconds. If all attempts fail, the last
exception is re-thrown.

When used in the explicit [`Pipeline`](Aop_Pipeline.md) (no attribute), the constructor
defaults for `times` and `backoff` are used.

## Public methods

### __construct() · <small>[🗎](../../src/Aop/RetryInterceptor.php#L21)</small>

`public function __construct(Psr\Log\LoggerInterface|null $logger = null, int $defaultTimes = 3, int $defaultBackoff = 0): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$logger` | Psr\Log\LoggerInterface\|null | `null` |  |
| `$defaultTimes` | int | `3` |  |
| `$defaultBackoff` | int | `0` |  |

**Return value**

- Type: `mixed`


---

### intercept() · <small>[🗎](../../src/Aop/RetryInterceptor.php#L27)</small>

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

[Back to the Index ⤴](README.md)
