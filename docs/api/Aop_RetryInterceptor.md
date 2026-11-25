# Class: RetryInterceptor

**Full name:** [Azera\Aop\RetryInterceptor](../../src/Aop/RetryInterceptor.php)

Retries methods marked with [`Retry`](Aop_Retry.md) on failure.

`times` is the total number of attempts (including the first). Between attempts the
interceptor sleeps for `backoff` milliseconds. If every attempt fails, the last exception
is re-thrown.

Explicit (pipeline) calls have no attribute; `defaultTimes` / `defaultBackoff` apply.

## Public methods

### __construct() · <small>[🗎](../../src/Aop/RetryInterceptor.php#L19)</small>

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

### intercept() · <small>[🗎](../../src/Aop/RetryInterceptor.php#L25)</small>

`public function intercept(Azera\Aop\Invocation $invocation, callable $next): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$invocation` | [Invocation](Aop_Invocation.md) | - |  |
| `$next` | callable | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
