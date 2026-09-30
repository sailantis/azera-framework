# Class: SqlGenerator

**Full name:** [Azera\Sync\SqlGenerator](../../src/Sync/SqlGenerator.php)

Converts SqlOperation objects into executable SQL strings.

Supports MySQL, PostgreSQL, and SQLite with driver-specific syntax
for each operation type.

## Public methods

### generate() · <small>[🗎](../../src/Sync/SqlGenerator.php#L19)</small>

`public function generate(array $operations, string $driver): array`

Generate SQL statements from an array of operations.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$operations` | array | - | The diff operations to convert |
| `$driver` | string | - | Database driver name: "mysql", "pgsql", or "sqlite" |

**Return value**

- Type: `array`
- Description: SQL statements, one per operation



---

[Back to the Index ⤴](README.md)
