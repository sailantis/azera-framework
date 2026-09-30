# Class: TransactionRolledBack

**Full name:** [Azera\Db\Event\TransactionRolledBack](../../src/Db/Event/TransactionRolledBack.php)

Dispatched after a transaction (or savepoint) has been rolled back via
[`Database::rollback()`](Db_Database.md#rollback).

## Public Properties

- `public readonly` bool `$nesting` · <small>[🗎](../../src/Db/Event/TransactionRolledBack.php)</small>
- `public readonly` int `$level` · <small>[🗎](../../src/Db/Event/TransactionRolledBack.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/TransactionRolledBack.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/TransactionRolledBack.php#L13)</small>

`public function __construct(Azera\Db\Database $database, bool $nesting, int $level): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$nesting` | bool | - |  |
| `$level` | int | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
