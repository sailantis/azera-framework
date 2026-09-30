# Interface: Cast

**Full name:** [Azera\Orm\Casting\Cast](../../src/Orm/Casting/Cast.php)

A value transformation between the PHP entity representation and the
raw store representation.

Direction conventions (used consistently across the ORM):

  encode()  PHP entity value   ->  raw store value (write path)
  decode()  raw store value    ->  PHP entity value (read path)

The store representation is what flows through the write pipeline and
the heap snapshots: EntityManager::extractData() encodes before values
reach a Store, and node->data holds the RAW encoded value so the diff
engine's `!==` comparison operates on stable scalars. The PHP
representation is what entity properties hold after hydration.

Implementations must be null-transparent (encode(null) === null,
decode(null) === null) and stateless — a single instance is shared by
all classes and rows of the registered type.

## Public methods

### encode() · <small>[🗎](../../src/Orm/Casting/Cast.php#L29)</small>

`public function encode(mixed $value): mixed`

PHP entity value -> raw store value.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### decode() · <small>[🗎](../../src/Orm/Casting/Cast.php#L34)</small>

`public function decode(mixed $value): mixed`

Raw store value -> PHP entity value.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
