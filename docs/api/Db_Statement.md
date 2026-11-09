# Class: Statement

**Full name:** [Azera\Db\Statement](../../src/Db/Statement.php)

A prepared statement bound to a SQL connection.

Unlike the legacy Database::prepare()/execute() pair, a PreparedStatement
owns its PDOStatement, so any number of statements can be prepared and
executed concurrently without clobbering a single shared slot.

## Public methods

### __construct() · <small>[🗎](../../src/Db/Statement.php#L35)</small>

`public function __construct(Azera\Db\Database $db, PDOStatement $statement, string $sql): mixed`

Create a new PreparedStatement wrapping an already-prepared PDO statement.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$db` | [Database](Db_Database.md) | - | SQL connection used to prepare the statement. |
| `$statement` | PDOStatement | - | The prepared PDO statement. |
| `$sql` | string | - | The original SQL string (used for error reporting). |

**Return value**

- Type: `mixed`


---

### execute() · <small>[🗎](../../src/Db/Statement.php#L52)</small>

`public function execute(array $params = []): Azera\Db\Statement|bool`

Execute the statement with the given bound parameters.

Deadlocks and connection-loss errors are retried through the connection's
auto-reconnect logic, mirroring Database::query().

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$params` | array | `[]` | Optional parameters to bind for this execution. |

**Return value**

- Type: [Statement](Db_Statement.md)|`bool`
- Description: Returns $this for chaining when the statement returns rows, true otherwise.

**Throws**

- Exception  On database errors.


---

### rowCount() · <small>[🗎](../../src/Db/Statement.php#L84)</small>

`public function rowCount(): int`

Return the number of rows affected by the last execution.

**Return value**

- Type: `int`


---

### columnCount() · <small>[🗎](../../src/Db/Statement.php#L93)</small>

`public function columnCount(): int`

Return the number of columns in the result set.

**Return value**

- Type: `int`


---

### getStatement() · <small>[🗎](../../src/Db/Statement.php#L102)</small>

`public function getStatement(): PDOStatement`

Return the underlying PDO statement instance.

**Return value**

- Type: `PDOStatement`


---

### getSql() · <small>[🗎](../../src/Db/Statement.php#L111)</small>

`public function getSql(): string`

Return the original SQL string this statement was prepared from.

**Return value**

- Type: `string`


---

### closeCursor() · <small>[🗎](../../src/Db/Statement.php#L127)</small>

`public function closeCursor(): void`

Close the cursor on the underlying PDO statement.

This releases any locks the statement may still hold.  It is especially
important for write statements that only partially consumed their result
set: on SQLite in WAL mode, an open cursor on a write statement keeps the
write lock held on its connection, blocking writes from other
connections.

**Return value**

- Type: `void`


---

### __destruct() · <small>[🗎](../../src/Db/Statement.php#L136)</small>

`public function __destruct(): mixed`

Ensure the underlying statement cursor is released when this statement
goes out of scope, so that any Database locks it holds are freed.

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
