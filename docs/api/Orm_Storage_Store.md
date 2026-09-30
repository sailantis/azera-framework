# Interface: Store

**Full name:** [Azera\Orm\Storage\Store](../../src/Orm/Storage/Store.php)

Persistence-level seam between the ORM and any storage backend.

Operations the EntityManager's write pipeline performs — NOT a query builder. SQL stores
implement it over a [`Database`](Db_Database.md); Mongo over the
mongodb library. The per-situation write strategies (RETURNING matrix)
live in each backend. A model belongs to exactly one store, routed by
metadata `store` (#[Entity(store: 'name')]) — the registry key
EntityManager::setStore() maps to an instance. Third-party backends:
implement this interface, register under a name, annotate #[Entity].

## Public methods

### txTarget() · <small>[🗎](../../src/Orm/Storage/Store.php#L24)</small>

`public function txTarget(array $meta): string`

Connection identity for the class described by $meta: two classes
sharing one txTarget share one transaction target in flush().

Borrowing stores (SQL) derive it from the write role; owning stores
return a constant token (their connection is fixed per instance).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array | - |  |

**Return value**

- Type: `string`


---

### insertOne() · <small>[🗎](../../src/Orm/Storage/Store.php#L32)</small>

`public function insertOne(string $class, array $data): array`

Persist one entity: INSERT or UPDATE (upsert when flagged).

Returns raw row(s) for backfill: ['row' => ?array, 'id' => int|string|null].

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$data` | array | - | column-name-keyed raw values |

**Return value**

- Type: `array`


---

### updateOne() · <small>[🗎](../../src/Orm/Storage/Store.php#L41)</small>

`public function updateOne(string $class, array $data, array $id): array`

Update one entity by PK values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$data` | array | - | column-name-keyed changed values |
| `$id` | array | - | PK field => value |

**Return value**

- Type: `array`


---

### upsertOne() · <small>[🗎](../../src/Orm/Storage/Store.php#L54)</small>

`public function upsertOne(string $class, array $data): array`

Atomic UPSERT: INSERT ... ON CONFLICT DO UPDATE (SQL) / updateOne
with upsert:true (Mongo). The caller-set PK is the conflict target —
$data must carry every PK column. Existence is resolved BY THE
DATABASE at write time: no prior SELECT, no insert-or-update guess.

Returns raw row(s) for backfill, same contract as insertOne
(RETURNING * when unset non-PK columns should refresh DB defaults).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$data` | array | - | column-name-keyed raw values (PK included) |

**Return value**

- Type: `array`


---

### deleteOne() · <small>[🗎](../../src/Orm/Storage/Store.php#L60)</small>

`public function deleteOne(string $class, array $id): void`

Delete one entity by PK values.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - | PK field => value |

**Return value**

- Type: `void`


---

### findBy() · <small>[🗎](../../src/Orm/Storage/Store.php#L69)</small>

`public function findBy(string $class, array $where): array`

Read raw rows. Returns plain assoc rows (no ResultSet).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$where` | array | - | PK field => value, or field                          => value |

**Return value**

- Type: `array`


---

### findByPk() · <small>[🗎](../../src/Orm/Storage/Store.php#L77)</small>

`public function findByPk(string $class, array $id): array|null`

Read one raw row by PK values (null when missing).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - | PK field => value |

**Return value**

- Type: `array`|`null`


---

### count() · <small>[🗎](../../src/Orm/Storage/Store.php#L83)</small>

`public function count(string $class, array $where = []): int`

Count matching rows.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$where` | array | `[]` | field => value |

**Return value**

- Type: `int`


---

### begin() · <small>[🗎](../../src/Orm/Storage/Store.php#L95)</small>

`public function begin(array|null $meta = null): void`

Open a transaction for $meta's write target. $meta (the scheduled
class's metadata) lets a store with per-class routing open the tx on
the class's OWN connection; a store MAY hold SEVERAL txs at once —
one per distinct connection target (PdoStore's tx map) — and a
begin() for an ALREADY-OPEN target must join it (no nesting).

Null = the store's default target.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `void`


---

### commit() · <small>[🗎](../../src/Orm/Storage/Store.php#L103)</small>

`public function commit(array|null $meta = null): void`

Commit the tx on $meta's write target — a tx THIS store began
(caller-opened txs are joined by routing and must never be
committed/rolled back by a store). Null meta commits EVERY tx the
store began (the legacy bare-call semantic, generalized to the map).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `void`


---

### rollback() · <small>[🗎](../../src/Orm/Storage/Store.php#L108)</small>

`public function rollback(array|null $meta = null): void`

Rollback — same target addressing as [`Store::commit()`](Orm_Storage_Store.md#commit).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `void`


---

### inTransaction() · <small>[🗎](../../src/Orm/Storage/Store.php#L115)</small>

`public function inTransaction(array|null $meta = null): bool`

Whether a transaction (or savepoint level) is active on $meta's
write target — store-begun OR caller-opened (the EM joins either
instead of double-beginning). Null meta: any of the store's targets.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array\|null | `null` |  |

**Return value**

- Type: `bool`


---

### enrichMetadata() · <small>[🗎](../../src/Orm/Storage/Store.php#L136)</small>

`public function enrichMetadata(array $meta, ReflectionClass $class): array`

Return $meta enriched (or throw for dishonorable attribute combos).

MUST stay JSON-serializable — the result feeds the L2 metadata cache.

Beyond pkMode, a store may contribute the recognized key
`castExclusions`: a list<string> of column TYPES whose registered
cast ([`Casts`](Orm_Casting_Casts.md)) is SUPPRESSED by default —
wire formats the backend owns natively (mongo: 'json', 'pgarray',
'datetime' — BSON maps PHP arrays and DateTimeInterface itself).
Per-column overrides: #[Column(cast: true)] forces the cast where
the store excluded it; #[Column(cast: false)] suppresses it where
the store would apply it. Resolved per column at compile time
(metadata 'cast' => bool) — the write pipeline stays metadata-driven
like everything else the EM consumes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$meta` | array | - | the freshly compiled generic metadata |
| `$class` | ReflectionClass | - | reflection of the compiled class |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
