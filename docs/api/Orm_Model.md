# Class: Model

**Full name:** [Azera\Orm\Model](../../src/Orm/Model.php)

Active-Record model over the [`EntityManager`](Orm_EntityManager.md).

ONE pipeline for everything: reads go through the EM's identity map
(`find()` / `findOne()` / `findAll()` — same PK read
twice in a request yields the same instance), writes delegate to
persist + flush (diff -> order -> transaction -> ID backfill). The
query builder remains available for advanced reads via `query()`
/ `with()` — its entities()/firstEntity() terminals hydrate onto
the SAME heap, so builder reads and facade reads share identity.

## Public methods

### source() · <small>[🗎](../../src/Orm/Model.php#L42)</small>

`public function source(): string`

Return the table or view name for this model.

Metadata-backed: #[Entity(name: ...)] > a declared source()
override > the naming convention (short class name, snake_case,
optional pluralization — e.g. User → users, AdminUser →
admin_users, Person → people). Overriding the method still wins
(dynamic > static); such an override may call parent::source()
for the convention value — the compile-time re-entrancy guard
keeps that recursion-free.

**Return value**

- Type: `string`


---

### schema() · <small>[🗎](../../src/Orm/Model.php#L60)</small>

`public function schema(): string|null`

Return the database schema for this model, if applicable
(e.g. PostgreSQL). Metadata-backed: #[Entity(schema: ...)] > a
declared schema() override > null.

**Return value**

- Type: `string`|`null`


---

### idFields() · <small>[🗎](../../src/Orm/Model.php#L79)</small>

`public function idFields(): array`

Return the primary key field(s) for this model.

Metadata-backed ('pkFields' — the resolved PK list): fields marked
#[Column(pk: true)] — multiple marks compose a composite key — or the
`id` column. Returns `[]` when the class declares no key at all. A
declared idFields() override is the authority (dynamic > static);
parent::idFields() from such an override resolves through the
compile-time re-entrancy guard.

**Return value**

- Type: `array`


---

### query() · <small>[🗎](../../src/Orm/Model.php#L98)</small>

`public static function query(string|null $alias = null): Azera\Db\Query`

Start a new query builder for this model. By default, it creates a Query with the model's source as the table.

Its entities()/firstEntity() terminals hydrate heap-tracked entities on the request-scoped identity map.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$alias` | string\|null | `null` | Optional alias for the model in the query |

**Return value**

- Type: [Query](Db_Query.md)


---

### with() · <small>[🗎](../../src/Orm/Model.php#L114)</small>

`public static function with(string ...$relations): Azera\Db\Query`

Start a query with eager-loaded relations.

BelongsTo/HasOne become LEFT JOINs (one SQL, alias-separated rows);
HasMany stays a second query by parent IDs. Relation names must be
declared via Orm attributes on the model.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$relations` | string | - | Relation names from Orm metadata |

**Return value**

- Type: [Query](Db_Query.md)


---

### fresh() · <small>[🗎](../../src/Orm/Model.php#L131)</small>

`public static function fresh(): Azera\Db\Query`

Start a fresh-read query: identity-map hits come back refreshed in
place (same instance, current values) instead of stale.

**Return value**

- Type: [Query](Db_Query.md)


---

### exists() · <small>[🗎](../../src/Orm/Model.php#L170)</small>

`public static function exists(array $conditions): bool`

Check whether any record matches the given conditions.

Builder-backed and therefore **SQL-only**: on a class routed to
another store (e.g. `#[Entity(store: 'mongo')]`) this throws a
`LogicException` rather than querying the wrong database. Use
`findOne($conditions) !== null` or `$em->findBy(...)` for a
backend-agnostic existence check.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | Associative array of field conditions to check for existence |

**Return value**

- Type: `bool`
- Description: True if a matching model exists, false otherwise


---

### count() · <small>[🗎](../../src/Orm/Model.php#L188)</small>

`public static function count(array $conditions = []): int`

Count records matching the given conditions.

Builder-backed and therefore **SQL-only** — see `exists()` for
the backend-agnostic alternative.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | `[]` | Associative array of field conditions to count |

**Return value**

- Type: `int`
- Description: The count of matching models


---

### save() · <small>[🗎](../../src/Orm/Model.php#L213)</small>

`public function save(): bool`

Save the model through the [`EntityManager`](Orm_EntityManager.md) — the ONE
write pipeline for facade and EM-direct use.

Untracked entity: adopt() first. With all ID fields set the baseline
is empty → the flush writes every set column (legacy blind-UPDATE
parity for manually built models); without IDs it schedules INSERT
and the EM backfills auto-generated PKs.

Returns true when a write happened (a no-op flush — nothing scheduled
— means the model was already in sync).

**Return value**

- Type: `bool`


---

### delete() · <small>[🗎](../../src/Orm/Model.php#L260)</small>

`public function delete(): bool`

Delete the model through the [`EntityManager`](Orm_EntityManager.md) (remove + flush —
one DELETE by PK identity). Requires that all ID fields are set;
throws otherwise.

The guard below is kept for its facade-specific message (documented in
`examples/SaveCreateUpdateExample.php`); `[`EntityManager::remove()`](Orm_EntityManager.md#remove)`
enforces the same invariant and adopts untracked instances, so nothing
else is needed here.

**Return value**

- Type: `bool`
- Description: True if the delete was successful


---

### refresh() · <small>[🗎](../../src/Orm/Model.php#L289)</small>

`public function refresh(): static|null`

Re-read this model's row from storage and refresh the instance IN
PLACE: current values onto $this, heap snapshot synced as the new
diff baseline. Returns $this, or NULL when the row is gone in
storage (detached from the identity map). Throws for untracked
models and models with scheduled unflushed writes.

**Return value**

- Type: `static`|`null`


---

### hasChanged() · <small>[🗎](../../src/Orm/Model.php#L304)</small>

`public function hasChanged(): bool`

Whether any field differs from the heap baseline (untracked entity:
true when any metadata column has a set value).

**Return value**

- Type: `bool`


---

### changedData() · <small>[🗎](../../src/Orm/Model.php#L315)</small>

`public function changedData(): array`

Field-name-keyed map of values that differ from the heap baseline
(untracked entity: all set values).

**Return value**

- Type: `array`


---

### loadState() · <small>[🗎](../../src/Orm/Model.php#L324)</small>

`public function loadState(): static`

Revert all properties to the values recorded in the heap node
snapshot (the loadState() replacement). No-op for untracked entities.

**Return value**

- Type: `static`


---

### setDefaultRole() · <small>[🗎](../../src/Orm/Model.php#L349)</small>

`public static function setDefaultRole(string $role): void`

Set both the read and write database role for this model class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | Named role registered with [`DatabaseManager`](Db_DatabaseManager.md). |

**Return value**

- Type: `void`


---

### setDefaultReadRole() · <small>[🗎](../../src/Orm/Model.php#L360)</small>

`public static function setDefaultReadRole(string $role): void`

Set the database role used for SELECT queries on this model class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | Named read role registered with [`DatabaseManager`](Db_DatabaseManager.md). |

**Return value**

- Type: `void`


---

### setDefaultWriteRole() · <small>[🗎](../../src/Orm/Model.php#L370)</small>

`public static function setDefaultWriteRole(string $role): void`

Set the database role used for INSERT/UPDATE/DELETE queries on this model class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | Named write role registered with [`DatabaseManager`](Db_DatabaseManager.md). |

**Return value**

- Type: `void`


---

### readRole() · <small>[🗎](../../src/Orm/Model.php#L407)</small>

`public function readRole(): string`

Return the database connection role used for read (SELECT) queries.

**Return value**

- Type: `string`
- Description: The role name (e.g. 'read', 'replica').


---

### writeRole() · <small>[🗎](../../src/Orm/Model.php#L417)</small>

`public function writeRole(): string`

Return the database connection role used for write (INSERT/UPDATE/DELETE) queries.

**Return value**

- Type: `string`
- Description: The role name (e.g. 'write', 'primary').


---

### readConnection() · <small>[🗎](../../src/Orm/Model.php#L429)</small>

`public function readConnection(): Azera\Db\Database`

Return the database connection used for read (SELECT) queries.

Resolves the configured read role via [`DatabaseManager::getOrDefault()`](Db_DatabaseManager.md#getordefault).

**Return value**

- Type: [Database](Db_Database.md)


---

### writeConnection() · <small>[🗎](../../src/Orm/Model.php#L442)</small>

`public function writeConnection(): Azera\Db\Database`

Return the database connection used for write (INSERT/UPDATE/DELETE) queries.

Resolves the configured write role via [`DatabaseManager::getOrDefault()`](Db_DatabaseManager.md#getordefault).

**Return value**

- Type: [Database](Db_Database.md)


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
