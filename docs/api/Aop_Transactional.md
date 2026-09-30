# Class: Transactional

**Full name:** [Azera\Aop\Transactional](../../src/Aop/Transactional.php)

Marks a method as transactional.

The [`TransactionalInterceptor`](Aop_TransactionalInterceptor.md) wraps the method in a database
transaction: begins before execution, commits on success, rolls back
on any Throwable.

Supports nested transactions via savepoints (see Database::begin(nesting: true)).

Example:
```php
#[Advised]
class BillingService
{
    #[Transactional]
    public function chargeSubscription(Account $a): void { ... }

    #[Transactional('analytics')]
    public function logEvent(Event $e): void { ... }
}
```

## Public Properties

- `public readonly` string|null `$connection` · <small>[🗎](../../src/Aop/Transactional.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Aop/Transactional.php#L30)</small>

`public function __construct(string|null $connection = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$connection` | string\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
