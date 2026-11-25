# Class: Document

**Full name:** [Azera\Orm\Document](../../src/Orm/Document.php)

Base class for MongoDB-backed objects (pairs with #[Entity(store: 'mongo')]).

FACADE over the [`EntityManager`](Orm_EntityManager.md): the whole static surface —
`find()`, `findOrFail()`, `findOne()`, `findAll()`,
`create()`, `upsert()`, `firstOrCreate()`,
`updateOrCreate()` — is shared with [`Model`](Orm_Model.md) via
`Concerns\EntityFacade` and goes through the Store seam, so it behaves
identically on documents and on SQL models. Writes delegate to the EM's
pipeline (persist + flush): documents and SQL models run the SAME
diff -> order -> transaction path and land in the SAME request-scoped heap.
The #[Entity] attribute's `store` selects the registered store type
(EntityManager::setStore()).

Deliberately NOT a [`Model`](Orm_Model.md) subclass: metadata PK resolution keys off
`isSubclassOf(Model::class)` to apply the SQL `['id']` chain, so extending
it would break the document `_id` convention. For the same reason the
builder-based readers (`query()`, `with()`, `fresh()`, `exists()`,
`count()`) are NOT available here — they compile SQL and would query the
wrong database; use the EntityManager for anything the facade cannot
express.

The EM's heap-node diff is authoritative — hydrated documents are
heap-tracked, so persist() schedules an UPDATE only when fields actually
changed.

## Public methods

### store() · <small>[🗎](../../src/Orm/Document.php#L43)</small>

`public function store(): string`

Which store type handles this document (the registry key
EntityManager::setStore() maps to an instance). Mirrors the
#[Entity(store: ...)] attribute; the attribute is the authority
(it compiles into metadata).

**Return value**

- Type: `string`


---

### save() · <small>[🗎](../../src/Orm/Document.php#L54)</small>

`public function save(): bool`

Save via the EM: INSERT when untracked, diff-UPDATE when managed.

The EM's heap-node diff is authoritative (hydrated documents are
heap-tracked with a baseline snapshot), so we schedule FIRST,
check whether anything was actually queued, and only then flush.

**Return value**

- Type: `bool`


---

### delete() · <small>[🗎](../../src/Orm/Document.php#L79)</small>

`public function delete(): bool`

Delete this document through the [`EntityManager`](Orm_EntityManager.md) (remove + flush —
one delete by the full key).

The key check below is kept for its facade-specific message; the
EntityManager enforces the same invariant (and adopts untracked
instances), so this only needs to fail early with a clearer error.

**Return value**

- Type: `bool`

**Throws**

- RuntimeException  when any PK field is unset


---

### hasChanged() · <small>[🗎](../../src/Orm/Document.php#L114)</small>

`public function hasChanged(): bool`

Whether any field differs from the heap baseline (untracked entity:
true when any metadata column has a set value).

**Return value**

- Type: `bool`


---

### changedData() · <small>[🗎](../../src/Orm/Document.php#L125)</small>

`public function changedData(): array`

Field-name-keyed map of values that differ from the heap baseline
(untracked entity: all set values).

**Return value**

- Type: `array`


---

### loadState() · <small>[🗎](../../src/Orm/Document.php#L134)</small>

`public function loadState(): static`

Revert all properties to the values recorded in the heap node
snapshot (the loadState() replacement). No-op for untracked entities.

**Return value**

- Type: `static`


---

### refresh() · <small>[🗎](../../src/Orm/Document.php#L145)</small>

`public function refresh(): static|null`

Re-read this document's row from storage and refresh the instance
IN PLACE (current values + synced snapshot). Returns $this, or NULL
when the row is gone in storage (detached). Throws for untracked
documents and documents with scheduled unflushed writes.

**Return value**

- Type: `static`|`null`


---

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
