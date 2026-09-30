# Class: SchemaDiff

**Full name:** [Azera\Sync\SchemaDiff](../../src/Sync/SchemaDiff.php)

Compares a PHP model definition against a database table schema and
produces SqlOperation objects that would bring the DB in line with
the model (PHP → DB direction).

This is the inverse of ModelDiff, which goes DB → PHP.

The diff is purely informational by default. Operations can be
converted to SQL via SqlGenerator and optionally executed.

## Public methods

### diff() · <small>[🗎](../../src/Sync/SchemaDiff.php#L21)</small>

`public function diff(Azera\Sync\ParsedModel $model, Azera\Sync\Schema\TableSchema $table): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [ParsedModel](Sync_ParsedModel.md) | - |  |
| `$table` | [TableSchema](Sync_Schema_TableSchema.md) | - |  |

**Return value**

- Type: `array`


---

### diffTableExists() · <small>[🗎](../../src/Sync/SchemaDiff.php#L122)</small>

`public function diffTableExists(Azera\Sync\ParsedModel $model, Azera\Sync\Schema\TableSchema|null $table): array`

Check if a table exists at all based on the diff.

If the model has no matching table, a CreateTable operation is needed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [ParsedModel](Sync_ParsedModel.md) | - |  |
| `$table` | [TableSchema](Sync_Schema_TableSchema.md)\|null | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
