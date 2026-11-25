# Class: TransactionalInterceptor

**Full name:** [Azera\Aop\TransactionalInterceptor](../../src/Aop/TransactionalInterceptor.php)

Wraps methods marked with [`Transactional`](Aop_Transactional.md) in a database transaction.

- Begins a transaction (or savepoint when nested) before the method
- Commits on success
- Rolls back on any Throwable, then re-throws

Resolution order for the connection role: the attribute's `connection` argument wins and
must name a configured role; otherwise the `defaultRole` constructor argument is used,
falling back to the manager's default connection when that role is not configured
(`write` by default).

## Public methods

### __construct() · <small>[🗎](../../src/Aop/TransactionalInterceptor.php#L23)</small>

`public function __construct(Azera\Db\DatabaseManager $dbManager, string $defaultRole = 'write', bool $detectLeakedConnections = false): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dbManager` | [DatabaseManager](Db_DatabaseManager.md) | - |  |
| `$defaultRole` | string | `'write'` |  |
| `$detectLeakedConnections` | bool | `false` |  |

**Return value**

- Type: `mixed`


---

### intercept() · <small>[🗎](../../src/Aop/TransactionalInterceptor.php#L40)</small>

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
