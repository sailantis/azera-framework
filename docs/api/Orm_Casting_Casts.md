# Class: Casts

**Full name:** [Azera\Orm\Casting\Casts](../../src/Orm/Casting/Casts.php)

Registry mapping metadata column types to [`Cast`](Orm_Casting_Cast.md) transformations.

The cast key is the COLUMN type declared (or inferred) in metadata —
`#[Column(type: 'json')]` or the property-type inference (array ->
'json', int -> 'int', ...). Inference only guesses the PORTABLE default:
an `array` property on PostgreSQL backed by a native array column must
be declared explicitly as `#[Column(type: 'pgarray')]`.

Registered built-ins (registered in [`Casts::boot()`](Orm_Casting_Casts.md#boot)):

  'int'      decode coerces strings -> int (both property AND snapshot)
  'float'    decode coerces strings -> float (both directions as int)
  'bool'     decode coerces '1'/'0'/'t'/'f'/... -> bool
  'json'     encode json_encode, decode json_decode(..., true)
  'pgarray'  PostgreSQL native array literal <-> 1-D scalar PHP array
  'datetime' DateTimeInterface <-> 'Y-m-d H:i:s' (decode yields
             DateTimeImmutable; replace the registration for a
             custom shape)

ENUM CLASS-STRINGS are keys too, with no registration step: metadata
records `columns[].type = MyEnum::class` (inferred from the property
type, or declared via #[Column(type: MyEnum::class)]) and the lookup
derives the [`EnumCast`](Orm_Casting_EnumCast.md) lazily. Deriving at LOOKUP time — rather
than registering during the metadata compile — is deliberate: compile()
runs only on a cache MISS, so a registration performed as a compile
side effect would silently disappear as soon as the L2 metadata cache
was warm, and the enum would start binding its case object to PDO.

Semantics:

- Registered casts apply on BOTH read and write paths; scalar casts
  exist because stringifying drivers (pdo_mysql with emulated prepares,
  pdo_pgsql) return numerics as strings — without them the typed
  property would coerce `int(5)` while the heap snapshot kept `"5"`,
  making diff() schedule a redundant UPDATE for every unchanged numeric
  column on the first persist after hydration.

- Applications can register additional types (encrypted columns, enums,
  money, ...): `Casts::register('encrypted', new EncryptedCast())`.
  Registration before the first Metadata::for() call of the class, or
  Metadata::clear() afterwards — FastHydrator compiles the decode plan
  per class once.

## Public methods

### register() · <small>[🗎](../../src/Orm/Casting/Casts.php#L72)</small>

`public static function register(string $type, Azera\Orm\Casting\Cast $cast): void`

Register (or replace) a cast for a column type.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$cast` | [Cast](Orm_Casting_Cast.md) | - |  |

**Return value**

- Type: `void`


---

### for() · <small>[🗎](../../src/Orm/Casting/Casts.php#L91)</small>

`public static function for(string $type): Azera\Orm\Casting\Cast|null`

The cast for a column type, or null when the type has no
transformation (values pass through raw in both directions).

Resolution order: an explicit registration, then the memoized
derived answer, then — for a BACKED ENUM class-string — the cast
derived from the type itself. Everything else is cast-free.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |

**Return value**

- Type: [Cast](Orm_Casting_Cast.md)|`null`


---

### forColumn() · <small>[🗎](../../src/Orm/Casting/Casts.php#L126)</small>

`public static function forColumn(array $col): Azera\Orm\Casting\Cast|null`

The ONE cast-resolution choke point: registry lookup GATED by the
per-column policy flag resolved at compile time
([`Metadata`](Orm_Metadata.md) 'columns[].cast'). Null = no cast
applies — either the type has none registered, or the column's
resolved policy says suppress (mongo's castExclusions, or an
explicit #[Column(cast: false)]). Every write AND read site goes
through this, so encode/decode always agree on what is shaped.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$col` | array | - | the metadata column entry |

**Return value**

- Type: [Cast](Orm_Casting_Cast.md)|`null`


---

### types() · <small>[🗎](../../src/Orm/Casting/Casts.php#L141)</small>

`public static function types(): array`

Registered type names (tests). DERIVED enum casts are absent by
design — they are resolved on demand, not registered.

**Return value**

- Type: `array`


---

### clear() · <small>[🗎](../../src/Orm/Casting/Casts.php#L151)</small>

`public static function clear(): void`

Drop the registry (tests) — built-ins re-register on next use.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
