# Class: EntityFacade

**Full name:** [Azera\Orm\Concerns\EntityFacade](../../src/Orm/Concerns/EntityFacade.php)

The static facade surface that works on EVERY store, shared by the facade
base classes ([`Model`](Orm_Model.md), [`Document`](Orm_Document.md)).

WHY A TRAIT: the two facades sit in separate hierarchies on purpose —
`Document` must NOT extend `Model`, because metadata PK resolution keys off
`isSubclassOf(Model::class)` to apply the SQL `['id']` chain, which would
break the document `_id` convention. Sharing the surface without sharing
the type keeps both the routing guarantee and the API parity.

WHY THESE METHODS ONLY: everything here goes through the EntityManager's
Store seam (`find()`, `findBy()`, `persist()`, `upsert()`), so it behaves
identically on SQL and on documents. The builder-based members
(`query()`, `with()`, `fresh()`, `exists()`, `count()`) compile SQL and are
therefore declared on `Model` alone, where `Query` refuses a non-SQL store
loudly instead of querying the wrong database.

Writes stay polymorphic: `create()`, `firstOrCreate()` and
`updateOrCreate()` call `static::save()`, so each facade keeps its own
insert/update policy.

Identity: a read hit returns the request-scoped heap instance, so a facade
read and an EM read of the same row are the SAME object.

## Public methods

### find() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L52)</small>

`public static function find(mixed $id, bool $fresh = false): static|null`

Find by its ID(s) through the EntityManager: heap probe first, one
Store read on miss, hydration onto the shared heap. The returned
instance is identity-mapped — the same row read twice in one request
yields the same object.

$fresh=true re-reads the row and refreshes the tracked instance IN
PLACE (same object, current values) — the polling-safe variant.
Throws when the entity carries scheduled unflushed writes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | mixed | - | Single ID value, or array of ID values (numeric list<br>matching pkFields order, or field => value map for<br>composite keys) |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `static`|`null`


---

### findOrFail() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L68)</small>

`public static function findOrFail(mixed $id, bool $fresh = false): static`

Find by ID(s) or throw when nothing matches.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | mixed | - | Single ID value or array of ID values (composite keys) |
| `$fresh` | bool | `false` | Re-read the row and refresh the tracked instance in place |

**Return value**

- Type: `static`

**Throws**

- RuntimeException  when not found


---

### findOne() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L87)</small>

`public static function findOne(array $conditions, bool $fresh = false): static|null`

Find the first match for the given conditions, or null. Conditions
are field => value equality, same shape the Store seam accepts.

$fresh=true refreshes already-tracked hits in place (same instance,
current values).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - |  |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `static`|`null`


---

### findAll() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L103)</small>

`public static function findAll(array $conditions = [], bool $fresh = false): array`

Find all matches for the given conditions (field => value equality),
in store row order, as identity-mapped instances.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | `[]` |  |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `array`


---

### create() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L120)</small>

`public static function create(array $values): static`

Build an instance from $values and persist it, returning it afterwards.

Flows through `save()`, so the class's own policy applies (an
auto-generated key is backfilled by the EntityManager).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | array | - | field => value |

**Return value**

- Type: `static`


---

### upsert() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L146)</small>

`public static function upsert(array $values): static`

Atomic UPSERT (INSERT ... ON CONFLICT DO UPDATE on SQL, `updateOne` with
`upsert: true` on documents): one statement, no prior SELECT — the
database resolves insert-vs-update, so there is no unique-violation
race. Every ID field must be present in $values, since the key is the
conflict target; on conflict, all non-ID fields from $values are
written.

With an incomplete key this degrades to a plain insert (there is no
conflict target to promise).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | array | - | field => value (must include all ID fields) |

**Return value**

- Type: `static`


---

### firstOrCreate() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L172)</small>

`public static function firstOrCreate(array $conditions, array $values = []): static`

Return the first match for $conditions, or create one from
$conditions merged with $values.

A read-then-write convenience, not a concurrency guarantee: two
callers racing the insert both miss and both create. Use
`upsert()` when the key is known and atomicity matters.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | field => value to match |
| `$values` | array | `[]` | additional values for the new instance |

**Return value**

- Type: `static`


---

### updateOrCreate() · <small>[🗎](../../src/Orm/Concerns/EntityFacade.php#L192)</small>

`public static function updateOrCreate(array $conditions, array $values = []): static`

Update the first match for $conditions with $values, or create one
from $conditions merged with $values when there is no match.

Same read-then-write caveat as `firstOrCreate()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | field => value to match |
| `$values` | array | `[]` | values to assign (or merge on create) |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
