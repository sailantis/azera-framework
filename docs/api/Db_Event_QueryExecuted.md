# Class: QueryExecuted

**Full name:** [Azera\Db\Event\QueryExecuted](../../src/Db/Event/QueryExecuted.php)

Dispatched after a SQL query has been executed via [`Database::query()`](Db_Database.md#query).

Carries the executed SQL, bound parameters, and the wall-clock duration
in milliseconds. Useful for query logging, slow-query detection, and
debugging.

## Public Properties

- `public readonly` string `$sql` · <small>[🗎](../../src/Db/Event/QueryExecuted.php)</small>
- `public readonly` array|null `$params` · <small>[🗎](../../src/Db/Event/QueryExecuted.php)</small>
- `public readonly` float `$durationMs` · <small>[🗎](../../src/Db/Event/QueryExecuted.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/QueryExecuted.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/QueryExecuted.php#L16)</small>

`public function __construct(Azera\Db\Database $database, string $sql, array|null $params, float $durationMs): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$sql` | string | - |  |
| `$params` | array\|null | - |  |
| `$durationMs` | float | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
