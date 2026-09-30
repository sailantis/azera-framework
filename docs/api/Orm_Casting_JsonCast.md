# Class: JsonCast

**Full name:** [Azera\Orm\Casting\JsonCast](../../src/Orm/Casting/JsonCast.php)

'json' cast: PHP arrays/objects <-> JSON text in a TEXT/JSON column.

Fixes the otherwise-broken array column path: without the cast,
extractData() hands the raw PHP array to PDO (which stringifies to
"Array") and hydration assigns the JSON text string onto the array-typed
property (TypeError).

Contract:
- encode: array|\stdClass -> json_encode (throw on failure); scalars
  and null pass through untouched (hand-written JSON strings, ints...)
  so the cast is safe for partially-typed data.
- decode: JSON text -> array (assoc); scalars pass through. Invalid
  JSON THROWS — fail loud, it means the column was edited outside the
  ORM and silently coercing would hide the corruption.
- null-transparent both directions.

On mongo documents this cast is SUPPRESSED by default (the store's
castExclusions — BSON owns array/object encoding natively);
#[Column(cast: true)] forces the text shape (values stored as a JSON
string) for cross-backend parity. The metadata type is still 'json'.

Snapshot contract: the property holds the DECODED array, node->data
holds the RAW JSON string — diff() compares the stable string form.
Reordering a JSON list reorders the encoded string and therefore counts
as a change (PHP `===` on list-likes is order-sensitive): accepted.

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/JsonCast.php#L34)</small>

`public function encode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/JsonCast.php#L52)</small>

`public function decode(mixed $value): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
