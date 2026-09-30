# Class: StatementPrepared

**Full name:** [Azera\Db\Event\StatementPrepared](../../src/Db/Event/StatementPrepared.php)

Dispatched after a SQL statement has been prepared via
[`Database::prepare()`](Db_Database.md#prepare).

## Public Properties

- `public readonly` string `$sql` · <small>[🗎](../../src/Db/Event/StatementPrepared.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/StatementPrepared.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/StatementPrepared.php#L13)</small>

`public function __construct(Azera\Db\Database $database, string $sql): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$sql` | string | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
