# Class: DatabaseExceptionOccurred

**Full name:** [Azera\Db\Event\DatabaseExceptionOccurred](../../src/Db/Event/DatabaseExceptionOccurred.php)

Dispatched when a PDO exception is caught and being processed by
[`Database::processPdoException()`](Db_Database.md#processpdoexception).

Listeners can use this for error logging, alerting, or metrics. The
exception may be re-thrown after processing; this event fires before
that decision is made.

## Public Properties

- `public readonly` PDOException `$exception` · <small>[🗎](../../src/Db/Event/DatabaseExceptionOccurred.php)</small>
- `public readonly` string|null `$sql` · <small>[🗎](../../src/Db/Event/DatabaseExceptionOccurred.php)</small>
- `public readonly` array|null `$params` · <small>[🗎](../../src/Db/Event/DatabaseExceptionOccurred.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/DatabaseExceptionOccurred.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/DatabaseExceptionOccurred.php#L18)</small>

`public function __construct(Azera\Db\Database $database, PDOException $exception, string|null $sql = null, array|null $params = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$exception` | PDOException | - |  |
| `$sql` | string\|null | `null` |  |
| `$params` | array\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
