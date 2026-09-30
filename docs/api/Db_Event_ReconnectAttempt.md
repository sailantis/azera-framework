# Class: ReconnectAttempt

**Full name:** [Azera\Db\Event\ReconnectAttempt](../../src/Db/Event/ReconnectAttempt.php)

Dispatched before each reconnection attempt in
[`Database::handleReconnect()`](Db_Database.md#handlereconnect).

## Public Properties

- `public readonly` int `$attempt` · <small>[🗎](../../src/Db/Event/ReconnectAttempt.php)</small>
- `public readonly` float `$delaySeconds` · <small>[🗎](../../src/Db/Event/ReconnectAttempt.php)</small>
- `public readonly` Throwable|null `$cause` · <small>[🗎](../../src/Db/Event/ReconnectAttempt.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/ReconnectAttempt.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/ReconnectAttempt.php#L14)</small>

`public function __construct(Azera\Db\Database $database, int $attempt, float $delaySeconds, Throwable|null $cause): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$attempt` | int | - |  |
| `$delaySeconds` | float | - |  |
| `$cause` | Throwable\|null | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
