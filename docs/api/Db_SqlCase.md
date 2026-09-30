# Class: SqlCase

**Full name:** [Azera\Db\SqlCase](../../src/Db/Sql.php)

Fluent builder for CASE expressions

## Public methods

### when() · <small>[🗎](../../src/Db/Sql.php#L509)</small>

`public function when(mixed $condition, mixed $then): static`

Add WHEN condition THEN result clause

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$condition` | mixed | - | Condition (scalar or Sql instance) |
| `$then` | mixed | - | Result value (scalar or Sql instance) |

**Return value**

- Type: `static`


---

### else() · <small>[🗎](../../src/Db/Sql.php#L520)</small>

`public function else(mixed $value): static`

Set ELSE default value

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | Default value (scalar or Sql instance) |

**Return value**

- Type: `static`


---

### end() · <small>[🗎](../../src/Db/Sql.php#L530)</small>

`public function end(): Azera\Db\Sql`

Finalize and return CASE expression as Sql

**Return value**

- Type: [Sql](Db_Sql.md)



---

[Back to the Index ⤴](README.md)
