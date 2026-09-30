# Class: ReconnectFailed

**Full name:** [Azera\Db\Event\ReconnectFailed](../../src/Db/Event/ReconnectFailed.php)

Dispatched when a reconnection attempt fails.

## Public Properties

- `public readonly` Throwable `$exception` · <small>[🗎](../../src/Db/Event/ReconnectFailed.php)</small>
- `public readonly` int `$attempt` · <small>[🗎](../../src/Db/Event/ReconnectFailed.php)</small>
- `public readonly` [Database](Db_Database.md) `$database` · <small>[🗎](../../src/Db/Event/ReconnectFailed.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Db/Event/ReconnectFailed.php#L13)</small>

`public function __construct(Azera\Db\Database $database, Throwable $exception, int $attempt): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$database` | [Database](Db_Database.md) | - |  |
| `$exception` | Throwable | - |  |
| `$attempt` | int | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
