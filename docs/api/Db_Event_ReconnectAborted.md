# Class: ReconnectAborted

**Full name:** [Azera\Db\Event\ReconnectAborted](../../src/Db/Event/ReconnectAborted.php)

Dispatched when all reconnection attempts have been exhausted.

## Public Properties

- `public readonly` int `$attempts` · <small>[🗎](../../src/Db/Event/ReconnectAborted.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/ReconnectAborted.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/ReconnectAborted.php#L12)</small>

`public function __construct(Azera\Db\Database $database, int $attempts): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$attempts` | int | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
