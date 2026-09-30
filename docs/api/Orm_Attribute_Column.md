# Class: Column

**Full name:** [Azera\Orm\Attribute\Column](../../src/Orm/Attribute/Column.php)

Declares a property as a persistent column.

Everything is optional: an unattributed declared property is still a
column with inferred defaults (name = property name, type from the PHP
type, falling back to 'string'). The attribute exists to override
those defaults.

`type` is inferred from the property's PHP type when omitted (int →
'int', float → 'float', bool → 'bool', array → 'json', DateTime* →
'datetime', a BACKED ENUM → the enum class-string, anything else →
'string'); pass `type:` explicitly to override (e.g. 'pgarray' for a
native pg array column).

ENUM properties are zero-config: the enum CLASS is used as the column
type, which is also the cast registry key, so the ORM derives the
matching enum cast from it (see [`Casts`](Orm_Casting_Casts.md)).
Two shapes are REFUSED at compile time, both naming the property:

- a PURE enum (no backing type) — it has no scalar representation, so
  nothing can round-trip through a store;
- an ENUM-TYPED property with `cast: false` — the cast IS the
  conversion between the case and the stored scalar, so suppressing it
  leaves the property unassignable on read and unbindable on write.
  Declare the property untyped (or scalar) if the raw representation
  is really what you want; metadata still records the enum class as
  the type, so `type:` is not needed to keep it.

`pk` explicitly marks (or excludes) a primary key: true marks the
column as part of the PK (composite keys = multiple marked columns);
false excludes a column the *_id name convention would wrongly mark.
An idFields() override on the model still wins over these marks.

`nullable` declares the column NULLABLE. The spelling on the PHP type
(`?int $views`) is the primary one; the attribute argument exists to
override the type's answer. The resolution is a TRI-STATE:

- null (default): the PHP type decides (`?T`/no type → nullable,
  `T` → not nullable). This is the zero-config path.
- true: the column is NULLABLE. Only meaningful as a redundant
  confirmation — it is accepted exactly where the property ALREADY
  accepts null (`?T`, untyped).
- false: the column is NOT nullable, even on a `?T` or untyped
  property. This is the informative direction: the DB column is
  NOT NULL while the property could hold a null it will never
  receive (a null arriving from the store is rejected on hydration).

Declaring `nullable: false` on a NON-nullable PHP type is a no-op
(that is already the default); declaring it on an untyped `public $x`
is a REQUEST (`$x` could hold null) and is honored.

`T` + `nullable: true` is REFUSED at compile time. A nullable column
needs a property that can receive the null: with a `T` property the
hydrator may only assign null (a TypeError) or throw (making the
attribute meaningless), since "leave the property uninitialized" is
indistinguishable from "never loaded". The remedy is `?T`, or drop
the attribute. Note this is the ONE refused combination — the mirror
case, `?T` + `nullable: false`, is perfectly consistent and allowed.

`persist: false` opts a property OUT of the column set entirely — it
is neither written nor read, and (being absent from the metadata) it
is also invisible to change tracking. Use it for runtime-only state
on a mutable property; static, readonly and non-public properties are
already excluded without an attribute.

`cast` controls the column type's cast
([`Casts`](Orm_Casting_Casts.md)) with a TRI-STATE semantic (the same
nullable-bool pattern as `pk`):

- null (default): AUTO — the class's STORE decides. Stores declare wire
  formats they own via the `castExclusions` metadata key contributed in
  enrichMetadata() (mongo excludes 'json', 'pgarray', 'datetime' — the
  driver maps PHP arrays and DateTimeInterface to BSON itself). Types
  not excluded are cast as on SQL (int/float/bool coercion + custom casts,
  plus enum class-strings, which always derive their cast).
- true: FORCE the cast even where the store excludes it (mongo stores a
  JSON text string / formatted datetime — the DB no longer owns the type).
- false: SUPPRESS the cast even on SQL (raw pass-through BOTH directions;
  the caller owns the stored representation — e.g. a 'json' column managed
  as raw text, or a driver-stringified numeric you do not want coerced).
  REFUSED on an ENUM-TYPED property (see `type` above).

## Public Properties

- `public` string|null `$type` · <small>[🗎](../../src/Orm/Attribute/Column.php)</small>
- `public` string|null `$name` · <small>[🗎](../../src/Orm/Attribute/Column.php)</small>
- `public` bool|null `$nullable` · <small>[🗎](../../src/Orm/Attribute/Column.php)</small>
- `public` bool `$persist` · <small>[🗎](../../src/Orm/Attribute/Column.php)</small>
- `public` bool|null `$pk` · <small>[🗎](../../src/Orm/Attribute/Column.php)</small>
- `public` bool|null `$cast` · <small>[🗎](../../src/Orm/Attribute/Column.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Attribute/Column.php#L90)</small>

`public function __construct(string|null $type = null, string|null $name = null, bool|null $nullable = null, bool $persist = true, bool|null $pk = null, bool|null $cast = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string\|null | `null` |  |
| `$name` | string\|null | `null` |  |
| `$nullable` | bool\|null | `null` |  |
| `$persist` | bool | `true` |  |
| `$pk` | bool\|null | `null` |  |
| `$cast` | bool\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
