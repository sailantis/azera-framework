# Class: DatabaseEvent

**Full name:** [Azera\Db\Event\DatabaseEvent](../../src/Db/Event/DatabaseEvent.php)

Base class for all database events.

All DB events are immutable value objects. They are dispatched through
[`AppContext::events()`](AppContext.md#events) â€” the unified event system â€” rather
than the old string-based `Database::fire()` mechanism.

Each event carries the `$database` instance so listeners can
inspect the connection (driver, transaction level, etc.) without
holding a separate reference.

## Public Properties

- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/DatabaseEvent.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/DatabaseEvent.php#L18)</small>

`public function __construct(Azera\Db\Database $database): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
