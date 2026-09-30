# Class: Retry

**Full name:** [Azera\Aop\Retry](../../src/Aop/Retry.php)

Marks a method as retryable.

The [`RetryInterceptor`](Aop_RetryInterceptor.md) retries the method on failure (Throwable)
up to the specified number of times, with optional backoff between
attempts.

Example:
```php
#[Advised]
class ApiService
{
    #[Retry(times: 3, backoff: 100)]
    public function callExternalApi(): Response { ... }
}
```

## Public Properties

- `public readonly` int `$times` · <small>[🗎](../../src/Aop/Retry.php)</small>
- `public readonly` int `$backoff` · <small>[🗎](../../src/Aop/Retry.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Retry.php#L25)</small>

`public function __construct(int $times = 3, int $backoff = 0): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$times` | int | `3` |  |
| `$backoff` | int | `0` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
