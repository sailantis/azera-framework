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

### source() · <small>[🗎](../../src/Orm/Model.php#L40)</small>

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

### schema() · <small>[🗎](../../src/Orm/Model.php#L58)</small>

`public function schema(): string|null`

Return the database schema for this model, if applicable
(e.g. PostgreSQL). Metadata-backed: #[Entity(schema: ...)] > a
declared schema() override > null.

**Return value**

- Type: `string`|`null`


---

### idFields() · <small>[🗎](../../src/Orm/Model.php#L76)</small>

`public function idFields(): array`

Return the primary key field(s) for this model.

Metadata-backed ('pkFields' — the resolved PK list): fields marked
#[Column(pk: true)] — multiple marks compose a composite key —
falling back to ['id']. A declared idFields() override is the
authority (dynamic > static); parent::idFields() from such an
override resolves through the compile-time re-entrancy guard.

**Return value**

- Type: `array`


---

### query() · <small>[🗎](../../src/Orm/Model.php#L95)</small>

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

### with() · <small>[🗎](../../src/Orm/Model.php#L111)</small>

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

### fresh() · <small>[🗎](../../src/Orm/Model.php#L128)</small>

`public static function fresh(): Azera\Db\Query`

Start a fresh-read query: identity-map hits come back refreshed in
place (same instance, current values) instead of stale.

**Return value**

- Type: [Query](Db_Query.md)


---

### create() · <small>[🗎](../../src/Orm/Model.php#L142)</small>

`public static function create(array $values): static`

Create a new model instance with the given values and save it to the database. Returns the created instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | array | - | Associative array of field values to set on the new model |

**Return value**

- Type: `static`
- Description: The created model instance


---

### upsert() · <small>[🗎](../../src/Orm/Model.php#L168)</small>

`public static function upsert(array $values): static`

Create or update a model using database-level UPSERT semantics
(INSERT ... ON CONFLICT DO UPDATE). A single atomic statement with
no prior SELECT — the database handles the conflict resolution.

Routed through the [`EntityManager`](Orm_EntityManager.md): the entity lands in the
identity map (MANAGED after flush) and the statement joins any open
flush transaction. All ID fields must be present in $values so the
conflict target is well-defined; on conflict, all non-ID fields
from $values are updated.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | array | - | Associative array of field values (must include all ID fields) |

**Return value**

- Type: `static`
- Description: The model instance with the given values


---

### firstOrCreate() · <small>[🗎](../../src/Orm/Model.php#L189)</small>

`public static function firstOrCreate(array $conditions, array $values = []): static`

Find the first model matching the given conditions or create a new one with the combined conditions and values if none found. This is useful for ensuring a record exists without creating duplicates. Returns the found or created instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | Associative array of field conditions to find the model |
| `$values` | array | `[]` | Additional values to set on the model if it needs to be created (merged with conditions) |

**Return value**

- Type: `static`
- Description: The found or created model instance


---

### updateOrCreate() · <small>[🗎](../../src/Orm/Model.php#L206)</small>

`public static function updateOrCreate(array $conditions, array $values = []): static`

Find the first model matching the given conditions or update it with the provided values if found, otherwise create a new one with the combined conditions and values. This is useful for ensuring a record exists and is up to date without creating duplicates. Returns the found, updated, or created instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | Associative array of field conditions to find the model |
| `$values` | array | `[]` | Values to set on the model if found (updated) or merged with conditions if created |

**Return value**

- Type: `static`
- Description: The found, updated, or created model instance


---

### find() · <small>[🗎](../../src/Orm/Model.php#L239)</small>

`public static function find(mixed $id, bool $fresh = false): static|null`

Find a model by its ID(s) through the EntityManager: heap probe
first, one Store read on miss, hydration onto the shared heap. The
returned instance is identity-mapped — the same row read twice in
one request yields the same object.

$fresh=true re-reads the row and refreshes the tracked instance IN
PLACE (same object, current values) — the polling-safe variant.
Throws when the entity carries scheduled unflushed writes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | mixed | - | Single ID value, or array of ID values (numeric list<br>matching idFields order, or field => value map for<br>composite keys) |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `static`|`null`


---

### findOrFail() · <small>[🗎](../../src/Orm/Model.php#L255)</small>

`public static function findOrFail(mixed $id, bool $fresh = false): static`

Finds a model by its ID(s) or throws an exception if not found

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | mixed | - | Single ID value or array of ID values (for composite keys) |
| `$fresh` | bool | `false` | Re-read the row and refresh the tracked instance in place |

**Return value**

- Type: `static`

**Throws**

- RuntimeException  if the model is not found


---

### findOne() · <small>[🗎](../../src/Orm/Model.php#L274)</small>

`public static function findOne(array $conditions, bool $fresh = false): static|null`

Find the first model matching the given conditions via the
EntityManager (bound parameters, metadata-mapped columns,
heap-tracked result), or null when nothing matches.

$fresh=true refreshes already-tracked hits in place (same instance,
current values).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | Associative array of field conditions to find the model |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `static`|`null`


---

### findAll() · <small>[🗎](../../src/Orm/Model.php#L290)</small>

`public static function findAll(array $conditions = [], bool $fresh = false): array`

Find all models matching the given conditions as heap-tracked
entities (identity-mapped, ordered by row order). If no conditions
are provided, it returns all models.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | `[]` | Associative array of field conditions to find the models |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `array`
- Description: The found model instances


---

### exists() · <small>[🗎](../../src/Orm/Model.php#L304)</small>

`public static function exists(array $conditions): bool`

Check if any model exists matching the given conditions. Returns true if at least one record matches, false otherwise.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | - | Associative array of field conditions to check for existence |

**Return value**

- Type: `bool`
- Description: True if a matching model exists, false otherwise


---

### count() · <small>[🗎](../../src/Orm/Model.php#L318)</small>

`public static function count(array $conditions = []): int`

Count the number of models matching the given conditions. Returns the count as an integer.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$conditions` | array | `[]` | Associative array of field conditions to count |

**Return value**

- Type: `int`
- Description: The count of matching models


---

### save() · <small>[🗎](../../src/Orm/Model.php#L389)</small>

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

### delete() · <small>[🗎](../../src/Orm/Model.php#L426)</small>

`public function delete(): bool`

Delete the model through the [`EntityManager`](Orm_EntityManager.md)
(adopt + remove + flush — one DELETE by PK identity). Requires that
all ID fields are set; throws otherwise.

**Return value**

- Type: `bool`
- Description: True if the delete was successful


---

### refresh() · <small>[🗎](../../src/Orm/Model.php#L451)</small>

`public function refresh(): static|null`

Re-read this model's row from storage and refresh the instance IN
PLACE: current values onto $this, heap snapshot synced as the new
diff baseline. Returns $this, or NULL when the row is gone in
storage (detached from the identity map). Throws for untracked
models and models with scheduled unflushed writes.

**Return value**

- Type: `static`|`null`


---

### hasChanged() · <small>[🗎](../../src/Orm/Model.php#L466)</small>

`public function hasChanged(): bool`

Whether any field differs from the heap baseline (untracked entity:
true when any metadata column has a set value).

**Return value**

- Type: `bool`


---

### changedData() · <small>[🗎](../../src/Orm/Model.php#L477)</small>

`public function changedData(): array`

Field-name-keyed map of values that differ from the heap baseline
(untracked entity: all set values).

**Return value**

- Type: `array`


---

### loadState() · <small>[🗎](../../src/Orm/Model.php#L486)</small>

`public function loadState(): static`

Revert all properties to the values recorded in the heap node
snapshot (the loadState() replacement). No-op for untracked entities.

**Return value**

- Type: `static`


---

### setDefaultRole() · <small>[🗎](../../src/Orm/Model.php#L511)</small>

`public static function setDefaultRole(string $role): void`

Set both the read and write database role for this model class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | Named role registered with [`DatabaseManager`](Db_DatabaseManager.md). |

**Return value**

- Type: `void`


---

### setDefaultReadRole() · <small>[🗎](../../src/Orm/Model.php#L522)</small>

`public static function setDefaultReadRole(string $role): void`

Set the database role used for SELECT queries on this model class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | Named read role registered with [`DatabaseManager`](Db_DatabaseManager.md). |

**Return value**

- Type: `void`


---

### setDefaultWriteRole() · <small>[🗎](../../src/Orm/Model.php#L532)</small>

`public static function setDefaultWriteRole(string $role): void`

Set the database role used for INSERT/UPDATE/DELETE queries on this model class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$role` | string | - | Named write role registered with [`DatabaseManager`](Db_DatabaseManager.md). |

**Return value**

- Type: `void`


---

### readRole() · <small>[🗎](../../src/Orm/Model.php#L569)</small>

`public function readRole(): string`

Return the database connection role used for read (SELECT) queries.

**Return value**

- Type: `string`
- Description: The role name (e.g. 'read', 'replica').


---

### writeRole() · <small>[🗎](../../src/Orm/Model.php#L579)</small>

`public function writeRole(): string`

Return the database connection role used for write (INSERT/UPDATE/DELETE) queries.

**Return value**

- Type: `string`
- Description: The role name (e.g. 'write', 'primary').


---

### readConnection() · <small>[🗎](../../src/Orm/Model.php#L591)</small>

`public function readConnection(): Azera\Db\Database`

Return the database connection used for read (SELECT) queries.

Resolves the configured read role via [`DatabaseManager::getOrDefault()`](Db_DatabaseManager.md#getordefault).

**Return value**

- Type: [Database](Db_Database.md)


---

### writeConnection() · <small>[🗎](../../src/Orm/Model.php#L604)</small>

`public function writeConnection(): Azera\Db\Database`

Return the database connection used for write (INSERT/UPDATE/DELETE) queries.

Resolves the configured write role via [`DatabaseManager::getOrDefault()`](Db_DatabaseManager.md#getordefault).

**Return value**

- Type: [Database](Db_Database.md)



---

[Back to the Index ⤴](README.md)
