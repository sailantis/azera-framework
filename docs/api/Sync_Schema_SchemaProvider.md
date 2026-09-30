# Interface: SchemaProvider

**Full name:** [Azera\Sync\Schema\SchemaProvider](../../src/Sync/Schema/SchemaProvider.php)

## Public methods

### listTables() · <small>[🗎](../../src/Sync/Schema/SchemaProvider.php#L11)</small>

`public function listTables(string|null $schema = null): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$schema` | string\|null | `null` | Database schema to scan (used by PostgreSQL; ignored by MySQL/SQLite).<br>When null the provider falls back to its engine default. |

**Return value**

- Type: `array`


---

### getTableSchema() · <small>[🗎](../../src/Sync/Schema/SchemaProvider.php#L18)</small>

`public function getTableSchema(string $table, string|null $schema = null): Azera\Sync\Schema\TableSchema`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$table` | string | - |  |
| `$schema` | string\|null | `null` | Database schema (used by PostgreSQL; ignored by MySQL/SQLite).<br>When null the provider falls back to its engine default. |

**Return value**

- Type: [TableSchema](Sync_Schema_TableSchema.md)



---

[Back to the Index ⤴](README.md)
