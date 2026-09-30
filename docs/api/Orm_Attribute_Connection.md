# Class: Connection

**Full name:** [Azera\Orm\Attribute\Connection](../../src/Orm/Attribute/Connection.php)

Class-level read/write connection-role configuration for SQL models.

Declarative alternative to setDefaultRole()/setDefaultReadRole()/
setDefaultWriteRole() bootstrap calls. Compiled into metadata, so
readRole()/writeRole() resolve it with zero runtime cost.

`role` sets both directions; explicit `read`/`write` win over it:

  #[Connection('primary')]                        // read + write
  #[Connection(read: 'replica', write: 'primary')] // split routing

Runtime setters on the concrete model class still win over the
attribute (per-request tenancy needs an imperative escape hatch);
the attribute wins over the base-model global override and over the
role-name fallback ('read'/'write').

## Public Properties

- `public` string|null `$role` · <small>[🗎](../../src/Orm/Attribute/Connection.php)</small>
- `public` string|null `$read` · <small>[🗎](../../src/Orm/Attribute/Connection.php)</small>
- `public` string|null `$write` · <small>[🗎](../../src/Orm/Attribute/Connection.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Attribute/Connection.php#L25)</small>

`public function __construct(string|null $role = null, string|null $read = null, string|null $write = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string\|null | `null` |  |
| `$read` | string\|null | `null` |  |
| `$write` | string\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
