# Class: Database

**Full name:** [Azera\Db\Database](../../src/Db/Database.php)

Class Database

## Public methods

### __construct() · <small>[🗎](../../src/Db/Database.php#L63)</small>

`public function __construct(string $dsn, string $user = '', string $pass = '', array $options = []): mixed`

Create a new database connection using the provided DSN, credentials and options.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dsn` | string | - |  |
| `$user` | string | `''` |  |
| `$pass` | string | `''` |  |
| `$options` | array | `[]` |  |

**Return value**

- Type: `mixed`

**Throws**

- Exception


---

### connect() · <small>[🗎](../../src/Db/Database.php#L97)</small>

`public function connect(): mixed`

Establish a new PDO connection using the current configuration

**Return value**

- Type: `mixed`

**Throws**

- Exception


---

### events() · <small>[🗎](../../src/Db/Database.php#L114)</small>

`public function events(): Psr\EventDispatcher\EventDispatcherInterface`

Resolve the event dispatcher from AppContext, cached on first call.

Returns a NullEventDispatcher when no real dispatcher is registered,
so dispatch() is always safe and cheap (single no-op method call).

**Return value**

- Type: `Psr\EventDispatcher\EventDispatcherInterface`


---

### setAutoReconnect() · <small>[🗎](../../src/Db/Database.php#L130)</small>

`public function setAutoReconnect(bool $enabled = true, int $maxAttempts = 0, float $retryDelay = 1, float $backoffMultiplier = 2, float $maxRetryDelay = 30, bool $jitter = true, callable|null $onReconnect = null): static`

Configure automatic reconnection behavior with detailed options

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$enabled` | bool | `true` | Enable or disable auto-reconnect |
| `$maxAttempts` | int | `0` | Maximum number of retry attempts (0 for unlimited) |
| `$retryDelay` | float | `1` | Initial delay between retries in seconds |
| `$backoffMultiplier` | float | `2` | Multiplier for exponential backoff |
| `$maxRetryDelay` | float | `30` | Maximum delay between retries in seconds |
| `$jitter` | bool | `true` | Whether to add random jitter to retry delays |
| `$onReconnect` | callable\|null | `null` | Optional callback invoked on successful reconnect (receives attempt number and db instance) |

**Return value**

- Type: `static`


---

### getAutoReconnect() · <small>[🗎](../../src/Db/Database.php#L155)</small>

`public function getAutoReconnect(): array|bool`

Get auto-reconnect configuration

**Return value**

- Type: `array`|`bool`


---

### query() · <small>[🗎](../../src/Db/Database.php#L167)</small>

`public function query(string $statement, array|null $params = null): PDOStatement|bool`

Execute a SQL statement with optional parameters and return the resulting statement or success status.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$statement` | string | - | SQL statement to execute |
| `$params` | array\|null | `null` | Optional parameters for prepared statements |

**Return value**

- Type: `PDOStatement`|`bool`

**Throws**

- Exception


---

### prepare() · <small>[🗎](../../src/Db/Database.php#L210)</small>

`public function prepare(string $statement): Azera\Db\Statement`

Prepare a SQL statement and return a Statement wrapper.

Each call returns an independent Statement that owns its PDO
statement, so any number of statements can be prepared and executed
concurrently without clobbering a single shared slot.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$statement` | string | - | SQL statement to prepare |

**Return value**

- Type: [Statement](Db_Statement.md)

**Throws**

- Exception


---

### processPdoException() · <small>[🗎](../../src/Db/Database.php#L237)</small>

`public function processPdoException(PDOException $exception, string $operation, string|null $sql = null, array|null $params = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$exception` | PDOException | - |  |
| `$operation` | string | - | The database operation that failed. |
| `$sql` | string\|null | `null` | The SQL statement that failed, if known. |
| `$params` | array\|null | `null` | Bound parameters for the failed statement, if known. |

**Return value**

- Type: `mixed`

**Throws**

- Exception


---

### selectRow() · <small>[🗎](../../src/Db/Database.php#L368)</small>

`public function selectRow(string $query, array|null $params = null, int $fetchMode = 0): array|bool`

Fetch a single row from the database as object, associative array, or numeric array depending on the specified fetch mode.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$query` | string | - |  |
| `$params` | array\|null | `null` |  |
| `$fetchMode` | int | `0` |  |

**Return value**

- Type: `array`|`bool`


---

### selectAll() · <small>[🗎](../../src/Db/Database.php#L383)</small>

`public function selectAll(string $query, array|null $params = null, int $fetchMode = 0): array`

Fetch all rows from the database as an array of objects, associative arrays, or numeric arrays depending on the specified fetch mode.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$query` | string | - |  |
| `$params` | array\|null | `null` |  |
| `$fetchMode` | int | `0` |  |

**Return value**

- Type: `array`


---

### rowCount() · <small>[🗎](../../src/Db/Database.php#L395)</small>

`public function rowCount(): int`

Return the number of rows affected by the last executed statement.

**Return value**

- Type: `int`
- Description: Number of affected rows, or 0 if no statement has been executed.


---

### getDefaultFetchMode() · <small>[🗎](../../src/Db/Database.php#L406)</small>

`public function getDefaultFetchMode(): int`

Driver's default row fetch mode — the mode ResultSet uses when no
explicit mode is given. The value is fixed by the constructor options,
so it is resolved once and cached; getAttribute() is not re-read for
every result set.

**Return value**

- Type: `int`


---

### inTransaction() · <small>[🗎](../../src/Db/Database.php#L420)</small>

`public function inTransaction(): bool`

Whether a transaction is currently active on this connection
(including nested savepoint levels).

**Return value**

- Type: `bool`


---

### lastInsertId() · <small>[🗎](../../src/Db/Database.php#L424)</small>

`public function lastInsertId(string|null $table = null, string|null $field = null): string|bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$table` | string\|null | `null` |  |
| `$field` | string\|null | `null` |  |

**Return value**

- Type: `string`|`bool`


---

### begin() · <small>[🗎](../../src/Db/Database.php#L460)</small>

`public function begin(bool $nesting = true): int|bool`

Begin a new transaction, or create a savepoint if nested transactions are enabled and a transaction is already active.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$nesting` | bool | `true` | Whether to use savepoints for nested transactions (if supported by the driver). |

**Return value**

- Type: `int`|`bool`
- Description: True or the number of affected rows on success.

**Throws**

- RuntimeException  If the transaction cannot be started.


---

### commit() · <small>[🗎](../../src/Db/Database.php#L504)</small>

`public function commit(bool $nesting = true): int|bool`

Commit the current transaction or release the current savepoint (for nested transactions).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$nesting` | bool | `true` | Whether to use savepoints for nested transactions (if supported by the driver). |

**Return value**

- Type: `int`|`bool`
- Description: True or the number of affected rows on success.

**Throws**

- RuntimeException  If there is no active transaction.


---

### rollback() · <small>[🗎](../../src/Db/Database.php#L551)</small>

`public function rollback(bool $nesting = true): int|bool`

Rollback the current transaction or to a savepoint if nesting is enabled and supported by the driver.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$nesting` | bool | `true` | Whether to use savepoints for nested transactions (if supported by the driver) |

**Return value**

- Type: `int`|`bool`

**Throws**

- Exception


---

### quote() · <small>[🗎](../../src/Db/Database.php#L597)</small>

`public function quote(string|null $str): string|bool`

Quote a string for use in a query.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$str` | string\|null | - |  |

**Return value**

- Type: `string`|`bool`


---

### quoteIdentifier() · <small>[🗎](../../src/Db/Database.php#L612)</small>

`public function quoteIdentifier(string|null ...$args): string`

Quote one or more identifier parts (schema, table, column) using the driver-appropriate quote character.

Parts are joined with a dot separator. NULL parts are skipped. "*" is passed through unquoted.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$args` | string\|null | - | Identifier parts to quote and join (e.g. schema, table, column). |

**Return value**

- Type: `string`
- Description: Fully quoted identifier string.


---

### getInternalConnection() · <small>[🗎](../../src/Db/Database.php#L641)</small>

`public function getInternalConnection(): PDO|null`

Return the underlying PDO connection instance.

**Return value**

- Type: `PDO`|`null`
- Description: The PDO instance, or null if not connected.


---

### builder() · <small>[🗎](../../src/Db/Database.php#L650)</small>

`public function builder(): Azera\Db\Query`

Create a new Query builder instance associated with this database connection.

**Return value**

- Type: [Query](Db_Query.md)


---

### supportsReturning() · <small>[🗎](../../src/Db/Database.php#L663)</small>

`public function supportsReturning(): bool`

Whether the connected server supports the RETURNING clause on INSERT/UPDATE/DELETE.

PostgreSQL supports it natively. MySQL 8.0.27+, MariaDB 10.5.0+ and SQLite 3.35+
also support it. Older servers must fall back to lastInsertId() for ID backfilling.

**Return value**

- Type: `bool`


---

### getDriver() · <small>[🗎](../../src/Db/Database.php#L697)</small>

`public function getDriver(): string`

Return the lowercase database driver name extracted from the DSN (e.g. "mysql", "pgsql", "sqlite").

**Return value**

- Type: `string`
- Description: Driver name.



---

[Back to the Index ⤴](README.md)
