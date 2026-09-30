# Class: Log

**Full name:** [Azera\Aop\Log](../../src/Aop/Log.php)

Marks a method for automatic logging.

The [`LogInterceptor`](Aop_LogInterceptor.md) logs method entry, exit, duration, and
any exceptions. Useful for debugging and audit trails.

Example:
```php
#[Advised]
class PaymentService
{
    #[Log(level: 'info')]
    public function processPayment(Payment $p): Result { ... }
}
```

## Public Properties

- `public readonly` string `$level` · <small>[🗎](../../src/Aop/Log.php)</small>
- `public readonly` bool `$logArgs` · <small>[🗎](../../src/Aop/Log.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Log.php#L24)</small>

`public function __construct(string $level = 'info', bool $logArgs = false): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$level` | string | `'info'` |  |
| `$logArgs` | bool | `false` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
