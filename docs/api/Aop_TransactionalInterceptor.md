# Class: TransactionalInterceptor

**Full name:** [Azera\Aop\TransactionalInterceptor](../../src/Aop/TransactionalInterceptor.php)

Intercepts methods marked with [`Transactional`](Aop_Transactional.md) and wraps them
in a database transaction.

- Begins a transaction (or savepoint if nested) before the method
- Commits on success
- Rolls back on any Throwable, then re-throws

The connection role can be specified via the attribute argument
(defaults to the write connection).

## Public methods

### __construct() · <small>[🗎](../../src/Aop/TransactionalInterceptor.php#L23)</small>

`public function __construct(Azera\Db\DatabaseManager $dbManager): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dbManager` | [DatabaseManager](Db_DatabaseManager.md) | - |  |

**Return value**

- Type: `mixed`


---

### intercept() · <small>[🗎](../../src/Aop/TransactionalInterceptor.php#L29)</small>

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
