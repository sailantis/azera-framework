# Class: StatementExecuted

**Full name:** [Azera\Db\Event\StatementExecuted](../../src/Db/Event/StatementExecuted.php)

Dispatched after a previously prepared statement has been executed via
[`Database::execute()`](Db_Database.md#execute).

## Public Properties

- `public readonly` array `$params` · <small>[🗎](../../src/Db/Event/StatementExecuted.php)</small>
- `public readonly` float `$durationMs` · <small>[🗎](../../src/Db/Event/StatementExecuted.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/StatementExecuted.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/StatementExecuted.php#L13)</small>

`public function __construct(Azera\Db\Database $database, array $params, float $durationMs): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$params` | array | - |  |
| `$durationMs` | float | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
