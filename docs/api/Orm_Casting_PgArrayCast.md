# Class: PgArrayCast

**Full name:** [Azera\Orm\Casting\PgArrayCast](../../src/Orm/Casting/PgArrayCast.php)

'pgarray' cast: PHP 1-D scalar array <-> PostgreSQL native array
literal (the string form PDO returns for array columns: `{1,2,3}`,
`{"a b","c\"d",NULL}`).

This is NOT JSON — json_decode('{1,2,3}') fails. A PHP array property
backed by a NATIVE pg array column must be declared
`#[Column(type: 'pgarray')]` (the inference default is 'json', the
portable form that works on mysql/sqlite/mongo).

Scope: arrays of scalars (int, float, bool, string, null), 1-D and
nested (2-D+) — encode() recurses into sub-arrays, decode() parses the
full literal grammar back to nested PHP arrays. Dimension regularity
(rectangular shape, matching depths) is validated by the SERVER at
write time: a ragged literal like `{{1},{2,3}}` binds fine and pg
rejects the INSERT — fail loud at flush, never silently wrong.
Nesting is capped at 6 levels (pg's own dimension limit) — deeper
input throws app-side instead of exhausting the stack.

Write direction: the literal binds as a plain string parameter — pg
infers the parameter type from the target column, so `'{"a b"}'`
against a text[] column just works.

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/PgArrayCast.php#L33)</small>

`public function encode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/PgArrayCast.php#L52)</small>

`public function decode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
