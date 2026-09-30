# Class: DateTimeCast

**Full name:** [Azera\Orm\Casting\DateTimeCast](../../src/Orm/Casting/DateTimeCast.php)

'datetime' cast: PHP DateTimeInterface <-> SQL DATETIME/TIMESTAMP text.

Contract:
- encode: DateTimeInterface -> 'Y-m-d H:i:s' (the canonical SQL DATETIME
  literal — the exact string the write pipeline hard-coded before this
  cast existed, so wire format is unchanged). Null and pre-formatted
  strings pass through — encode is idempotent.
- decode: parseable datetime text -> DateTimeImmutable; null and
  non-string values pass through. Unparseable text THROWS — fail loud,
  it means the column was edited outside the ORM or holds a format the
  parser cannot read (same corruption policy as JsonCast/BoolCast).

Immutable decode is the deliberate default: entities share the
request-scoped heap, and a mutable DateTime on a tracked entity could
be modified in place without any property reassignment, silently
drifting from the diff snapshot. Applications that want a different
shape (mutable DateTime, Carbon, a custom wire format, timezone
normalization) REPLACE the registration — the datetime shaping lives
in the registry precisely so it is user-overridable, not hard-coded:

  Casts::register('datetime', new MyDateTimeCast());

Register before the first Metadata::for()/hydration of the affected
class (or FastHydrator::clear() after) — the decode plan is compiled
per class. Write-side shaping (EntityManager::extractData) picks the
replacement up immediately.

On mongo documents this cast is EXCLUDED by default (the store's
castExclusions — BSON maps DateTimeInterface to BSON dates itself);
#[Column(cast: true)] forces the SQL text shape onto a mongo column.

Snapshot contract: the property holds the decoded DateTimeImmutable,
node->data holds the canonical string (encode(decode(raw)) round-trip)
— diff() compares stable scalars.

## Public Constants

- **FORMAT** = `'Y-m-d H:i:s'`

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/DateTimeCast.php#L46)</small>

`public function encode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/DateTimeCast.php#L55)</small>

`public function decode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
