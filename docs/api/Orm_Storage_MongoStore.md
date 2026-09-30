# Class: MongoStore

**Full name:** [Azera\Orm\Storage\MongoStore](../../src/Orm/Storage/MongoStore.php)

MongoDB backend of the [`Store`](Orm_Storage_Store.md) seam over the mongodb/mongodb library.

The stack is two layers, NOT two alternatives (unlike redis's phpredis vs
predis): ext-mongodb (PECL) is THE driver — MongoDB\Driver\Manager, BSON
encoding, wire protocol — and mongodb/mongodb (composer) is the pure-PHP
convenience API on top of it (`Client`, `MongoCollection`). The
library cannot run without the extension; using this store = using both.

OWNS its connection (the inverse of PdoStore's borrow model): mongo has no
role-based read/write split in the DatabaseManager, so the store wraps one
`Client` and resolves the per-class collection from metadata
(`collection` ?? snake/plural convention). A class belongs to exactly one
collection, declared by #[Entity(name)].

Constructor accepts EITHER a Client (production) OR a collection resolver
`fn(string $name): MongoCollection` — the test seam: an in-memory fake
collection keeps the suite hermetic (no live server, no flaky CI).

Rows are plain assoc arrays keyed by the metadata COLUMN names — identical
shape contract to PdoStore — so EntityManager's write pipeline, heap diff,
and FastHydrator work unchanged.

Identity: mongo's `_id` is THE PK — single, always present (driver-generated
ObjectId on insert when omitted). The metadata pk convention (*_id marks)
already resolves `$_id` for documents, and insert backfill maps the
inserted id onto it.

Transactions: no-ops. Multi-document ACID needs replica-set sessions —
deliberately deferred (documented); the Store seam's begin/commit/rollback
is satisfied structurally so the EM pipeline works against single-server
deployments.

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L69)</small>

`public function __construct(MongoDB\Client|callable $clientOrResolver, string $database = 'test'): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$clientOrResolver` | MongoDB\Client\|callable | - | Optional-dependency boundary: mongodb/mongodb is a `suggest` (not<br>`require`). The `use MongoDB\...` imports here are lazy aliases —<br>loading this class never fatals — and the resolver seam (test<br>fakes) needs no package at all. The failure shape that can actually<br>occur without the package: a real Client instance CANNOT be passed<br>(its class doesn't exist, so it can't be constructed anywhere), so<br>a non-callable argument can only be a mistake — most likely a DSN<br>string in the Client-ctor shape. Convert the cryptic union<br>TypeError into the actionable install hint. |
| `$database` | string | `'test'` |  |

**Return value**

- Type: `mixed`


---

### insertOne() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L94)</small>

`public function insertOne(string $class, array $data): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$data` | array | - |  |

**Return value**

- Type: `array`


---

### updateOne() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L115)</small>

`public function updateOne(string $class, array $data, array $id): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$data` | array | - |  |
| `$id` | array | - |  |

**Return value**

- Type: `array`


---

### upsertOne() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L128)</small>

`public function upsertOne(string $class, array $data): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$data` | array | - |  |

**Return value**

- Type: `array`


---

### deleteOne() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L159)</small>

`public function deleteOne(string $class, array $id): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - |  |

**Return value**

- Type: `void`


---

### findBy() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L165)</small>

`public function findBy(string $class, array $where): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$where` | array | - |  |

**Return value**

- Type: `array`


---

### findByPk() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L173)</small>

`public function findByPk(string $class, array $id): array|null`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - |  |

**Return value**

- Type: `array`|`null`


---

### count() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L181)</small>

`public function count(string $class, array $where = []): int`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$where` | array | `[]` |  |

**Return value**

- Type: `int`


---

### begin() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L195)</small>

`public function begin(array|null $meta = null): void`

No-ops: multi-document ACID needs replica-set sessions (deferred).

Kept structural so the EM pipeline never branches on store type.
$meta ignored — an owning store has exactly ONE fixed write target,
so there is nothing to address per class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `void`


---

### commit() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L197)</small>

`public function commit(array|null $meta = null): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `void`


---

### rollback() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L199)</small>

`public function rollback(array|null $meta = null): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `void`


---

### inTransaction() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L201)</small>

`public function inTransaction(array|null $meta = null): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `bool`


---

### enrichMetadata() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L230)</small>

`public function enrichMetadata(array $meta, ReflectionClass $class): array`

Contribute document-specific metadata during compile:

- pkMode = 'convention': documents resolve their PK via the id/*_id
  NAME convention (not the SQL Model chain) — this is what keeps
  `_id` resolving as the PK. Model-ness alone cannot decide (mongo
  documents may extend Model too); only the store knows.
- castExclusions = ['json', 'pgarray', 'datetime']: the wire formats
  BSON owns natively — the driver maps PHP arrays and
  DateTimeInterface itself, so those casts are SUPPRESSED by default
  (metadata 'cast' => false per column; #[Column(cast: true)] forces
  one back). Scalar casts (int/float/bool) stay ACTIVE: their decode
  is a no-op on native BSON values, and custom user-registered casts
  keep working unless explicitly opted out.
- #[Connection] rejected: this store OWNS its client (the inverse
  of PdoStore's borrow model) — multiple mongo connections are
  modeled as multiple registered store types ('mongo-eu', …),
  selected by #[Entity(store: ...)].

Collection resolution stays generic: metadata `source` (#[Entity(name)])
with the snake/plural convention as fallback — no per-backend key.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array | - |  |
| `$class` | ReflectionClass | - |  |

**Return value**

- Type: `array`


---

### txTarget() · <small>[🗎](../../src/Orm/Storage/MongoStore.php#L284)</small>

`public function txTarget(array $meta): string`

No transactions: one connection per store instance, so the identity
token is constant. begin()/commit()/rollback() are no-ops anyway.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array | - |  |

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
