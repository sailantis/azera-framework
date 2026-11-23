# Class: EnumCast

**Full name:** [Azera\Orm\Casting\EnumCast](../../src/Orm/Casting/EnumCast.php)

Backed-enum cast: enum cases <-> their scalar backing values.

The cast key IS the enum class-string (see [`Casts`](Orm_Casting_Casts.md)): metadata
records `columns[].type = MyEnum::class` — inferred from the property
type, or declared with #[Column(type: MyEnum::class)] — and the
registry derives the matching instance lazily via
[`EnumCast::for()`](Orm_Casting_EnumCast.md#for). There is therefore no registration step for an
application to remember, and nothing to replay after the L2 metadata
cache warms (compile() is skipped on a cache hit, so a cast
registered as a compile side effect would silently vanish).

Contract:
- encode: a case -> its `->value`; a backing scalar -> the canonical
  `->value`; null -> null. Lenient on scalars so the id backfill and
  `cast: false` partial-data paths keep working.
- decode: a backing scalar (including the driver's STRING form of an
  int backing) -> the case; a case passes through.
- Anything else THROWS in BOTH directions. A corrupted or mis-typed
  column must stay loud rather than decode to null, which would then
  be written back over the original value.

The snapshot contract ([`FastHydrator::put()`](Orm_FastHydrator.md#put)) is why
encode() and decode() must be inverse: the property holds the CASE
while `node->data` holds the scalar, so an unchanged hydrated entity
diffs clean and emits no UPDATE.

The backing type is resolved ONCE in the constructor because
`from()`/`tryFrom()` follow the typing rules of the CALL site: under
strict_types an int-backed enum called with the string '2' raises a
TypeError instead of matching, so drivers that return numerics as
strings (pdo_pgsql, emulated-prepare MySQL) must be normalized HERE
and never handed to tryFrom() raw.

Instances are memoized per enum class, so one instance is shared by
every class and row using that enum.

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Casting/EnumCast.php#L56)</small>

`public function __construct(string $enumClass): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$enumClass` | string | - |  |

**Return value**

- Type: `mixed`


---

### for() · <small>[🗎](../../src/Orm/Casting/EnumCast.php#L91)</small>

`public static function for(string $enumClass): self`

Memoized instance for an enum class — the registry entry for an
enum class-string column type.

Instances live for the PROCESS, like the built-in casts: they are
immutable (backing type resolved once) and stateless, so sharing
one across registry clears, classes and rows is safe. Replacing an
enum's cast is done through [`Casts::register()`](Orm_Casting_Casts.md#register), not here.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$enumClass` | string | - |  |

**Return value**

- Type: `self`


---

### encode() · <small>[🗎](../../src/Orm/Casting/EnumCast.php#L96)</small>

`public function encode(mixed $value): string|int|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `string`|`int`|`null`


---

### decode() · <small>[🗎](../../src/Orm/Casting/EnumCast.php#L120)</small>

`public function decode(mixed $value): BackedEnum|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `BackedEnum`|`null`



---

[Back to the Index ⤴](README.md)
