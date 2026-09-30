# Class: EntityManager

**Full name:** [Azera\Orm\EntityManager](../../src/Orm/EntityManager.php)

The entity manager: identity map + write pipeline, consolidated.

One instance per request (registered request-scoped in AppContext).
The Active-Record facades ([`Model`](Orm_Model.md),
[`Document`](Orm_Document.md)) delegate here, so facade-style and
EM-direct use share ONE write pipeline: diff -> topological order ->
transaction -> ID backfill.

Reads probe the identity map first - find() hit = the same instance,
miss = one Store read + FastHydrator onto the shared heap. Works for
SQL models (PdoStore) and Mongo documents.

The write pipeline used to be a separate UnitOfWork class; it is merged
here because it had exactly one caller and no public surface beyond
persist/remove/flush. flush() = diff -> commands -> topological order
(owners before dependents, so auto-generated owner PKs backfill into
dependents' FK values) -> execute -> backfill IDs -> mark MANAGED,
inside the Store's transaction (nested transactions already supported
by Database). Fast path: a trivial single-entity flush adds only
scheduling overhead, and no tx when one is already open (joins the
caller's tx).

Storage-agnostic: writes execute through the [`Store`](Orm_Storage_Store.md) seam resolved
from class metadata (#[Entity(store: ???)] - an opaque registry key). The
SQL shapes and the RETURNING matrix (pk_set / returning_id /
returning_all / last_insert_id) live in each Store backend; flush
consumes their normalized ['row' => ?array, 'id' => ?scalar] results
for identity backfill.

RequestScoped: `resetState()` wipes the heap and drops scheduled
writes between requests in persistent workers (non-negotiable - same
contract as Heap).

## Public methods

### __construct() · <small>[🗎](../../src/Orm/EntityManager.php#L49)</small>

`public function __construct(Azera\Orm\Heap $heap, object|null $db = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$heap` | [Heap](Orm_Heap.md) | - |  |
| `$db` | object\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### heap() · <small>[🗎](../../src/Orm/EntityManager.php#L59)</small>

`public function heap(): Azera\Orm\Heap`

The shared identity map.

**Return value**

- Type: [Heap](Orm_Heap.md)


---

### find() · <small>[🗎](../../src/Orm/EntityManager.php#L83)</small>

`public function find(string $class, array $id, bool $fresh = false): object|null`

Load one entity by PK values: heap probe first, Store read on miss,
hydration onto the shared heap.

$fresh=false (default): a heap hit returns the tracked instance
WITHOUT touching the store - the identity-map behavior.

$fresh=true: the row is RE-READ from the Store and applied onto the
tracked instance in place (`refresh()`) - same object, current
values. Use this for stale-read-sensitive work (polling tasks,
cross-request workers between resetState() boundaries). Entities
with scheduled (unflushed) writes must NOT be fresh-read - refresh()
throws instead of silently discarding pending work.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - | PK field => value |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `object`|`null`


---

### findBy() · <small>[🗎](../../src/Orm/EntityManager.php#L123)</small>

`public function findBy(string $class, array $where, bool $fresh = false): array`

Load all entities matching field => value conditions.

$fresh=true refreshes already-tracked entities in place from the
fresh rows (same instances, current values); entities with pending
scheduled writes keep their in-request state.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$where` | array | - |  |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `array`


---

### refresh() · <small>[🗎](../../src/Orm/EntityManager.php#L146)</small>

`public function refresh(object $entity): object|null`

Re-read an entity's row from the Store and refresh the tracked
instance IN PLACE: current row values onto the entity, node snapshot
synced as the new diff baseline. Identity is preserved - the caller
keeps its reference, the data is current. The escape hatch that
keeps the identity-map's correctness (one row = one object, no lost
in-request writes) while serving freshness-sensitive reads
(polling, long-lived workers between request boundaries).

Returns the entity when refreshed. Returns NULL when the row is
GONE in storage - the entity is detached (the identity map mirrors
the store; a tracked ghost would keep coming back on heap-hit
reads). Guards: untracked entities throw (nothing to refresh
against - find()/track() first); entities with scheduled unflushed
writes throw (a re-read would clobber queued work - flush() first).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `object`|`null`


---

### persist() · <small>[🗎](../../src/Orm/EntityManager.php#L182)</small>

`public function persist(object $entity): static`

Queue an entity for INSERT (or UPDATE when already managed).

Explicit intent - flush() sees ONLY what was persisted here
(the deliberate no-implicit-dirty-checking doctrine contrast).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `static`


---

### upsert() · <small>[🗎](../../src/Orm/EntityManager.php#L207)</small>

`public function upsert(object $entity): static`

Queue a single-statement UPSERT (INSERT ... ON CONFLICT DO UPDATE /
mongo updateOne upsert:true): the DATABASE resolves insert-vs-update
at write time - no prior SELECT, no insert-or-update guess, no
unique-violation race. Deliberately intent-based like persist(): the
caller asserts "row with this PK should exist afterwards", and the
store makes it so atomically.

Requires a full identity (every PK field set) - the PK is the
conflict target. Anything less is an ordinary insert.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `static`


---

### remove() · <small>[🗎](../../src/Orm/EntityManager.php#L236)</small>

`public function remove(object $entity): static`

Queue an entity for DELETE. Never-persisted entities (or cancelled
pending inserts) are just dropped from identity tracking.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `static`


---

### flush() · <small>[🗎](../../src/Orm/EntityManager.php#L265)</small>

`public function flush(): void`

Execute all scheduled writes in ONE transaction
(diff -> order -> execute -> backfill).

Transaction control follows the SCHEDULED WORK's (store, txTarget)
grouping: all scheduled classes must resolve to ONE store instance
AND one connection target on it - otherwise the flush spans two
connections and cannot be atomic, which throws (stores may relax
this with per-instance semantics via txTarget(); e.g. a mongo
store's no-op txs group under its single instance token). Use
[`EntityManager::flushAll()`](Orm_EntityManager.md#flushall) for write sets that legitimately
span connections (per-target txs, best-effort all-or-nothing).

**Return value**

- Type: `void`


---

### flushAll() · <small>[🗎](../../src/Orm/EntityManager.php#L328)</small>

`public function flushAll(): void`

Execute all scheduled writes across EVERY connection they touch.

The escape hatch for write sets that legitimately span multiple
stores/connections (e.g. SQL + mongo, or several #[Connection]
write roles): ONE global topological pass executes every node (an
owner in one group can feed its PK into a dependent in another),
and each store/connection target commits its own tx - begun lazily
when its first node executes.

Failure semantics are best-effort all-or-nothing, mirroring
flush()'s shape: if any write (or a commit) throws mid-pass, every
tx begun SO FAR is rolled back; groups whose commit already ran
stay committed. Cross-connection atomicity does not exist - use
flush() when the whole write set shares one connection target.

**Return value**

- Type: `void`


---

### detach() · <small>[🗎](../../src/Orm/EntityManager.php#L398)</small>

`public function detach(object $entity): void`

Drop an entity from identity tracking (no storage effect).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `void`


---

### adopt() · <small>[🗎](../../src/Orm/EntityManager.php#L421)</small>

`public function adopt(object $entity): object`

Facade adoption: register an EXTERNALLY-loaded entity as MANAGED
with an EMPTY baseline.

Reads that bypass the EM pipeline (FETCH_CLASS ResultSet, Paginator)
produce instances that are not in the heap. The facade calls adopt()
before persisting. Empty baseline means the next flush writes every
set non-PK column - the legacy blind-UPDATE parity for manually
built ID'd entities (and the correct semantic: the EM cannot know
what the DB already holds for an entity it never loaded).

Entities loaded through EM reads (find/findBy/entities) do NOT need
adopt() - their heap node carries the store snapshot from hydration.
The returned entity is the ADOPTED instance (heap re-attach replaces
the node when the entity already sits under another identity).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `object`


---

### track() · <small>[🗎](../../src/Orm/EntityManager.php#L448)</small>

`public function track(object $entity): object`

Register an externally-loaded entity as MANAGED with its CURRENT
values as the baseline (the "already in sync" adoption for entities
loaded by reads the EM does not hydrate - FETCH_CLASS ResultSet,
Paginator). Unlike adopt(), persist() on a tracked() entity emits
SQL only for fields changed after the track() call.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `object`


---

### contains() · <small>[🗎](../../src/Orm/EntityManager.php#L470)</small>

`public function contains(object $entity): bool`

Whether the entity is tracked in the request heap.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `bool`


---

### isScheduled() · <small>[🗎](../../src/Orm/EntityManager.php#L478)</small>

`public function isScheduled(object $entity): bool`

Whether the entity has scheduled work in the current flush cycle.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `bool`


---

### dirtyData() · <small>[🗎](../../src/Orm/EntityManager.php#L502)</small>

`public function dirtyData(object $entity): array`

Dirty state backed by the heap node snapshot (the ONE diff engine ???
Stateful's clone snapshot is gone).

Untracked entity: every metadata column with a set value counts as
changed (the same "everything set is pending" semantic the old
no-snapshot Stateful path had). Tracked entity: current values vs
the node snapshot, field-name-keyed - PK columns EXCLUDED there:
identity, not data (the pipeline never puts a PK into an UPDATE
SET, so isDirty()/hasChanged() must match what flush() would
actually write; a mutated PK on a tracked entity is the identity
guard's problem, not a data diff).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `array`
- Description: field name => current value


---

### isDirty() · <small>[🗎](../../src/Orm/EntityManager.php#L550)</small>

`public function isDirty(object $entity): bool`

Whether the entity differs from its heap baseline (untracked entity:
true - it has pending state that adopt+flush would write).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `bool`


---

### revert() · <small>[🗎](../../src/Orm/EntityManager.php#L560)</small>

`public function revert(object $entity): void`

Revert the entity's properties to the values recorded in its heap
node snapshot (the loadState() replacement). No-op for untracked
entities - nothing to revert to.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `void`


---

### clear() · <small>[🗎](../../src/Orm/EntityManager.php#L591)</small>

`public function clear(): void`

Wipe ALL tracked state (identity + scheduled writes). Scheduled
work is dropped, NOT flushed - explicit clear means "forget".

**Return value**

- Type: `void`


---

### resetState() · <small>[🗎](../../src/Orm/EntityManager.php#L603)</small>

`public function resetState(): void`

Request-scoped hook: wipe the identity map + any scheduled writes
between requests in persistent workers. Also drops the memoized
fallback store - a worker re-pointing DatabaseManager roles (tenant
swap) must not keep a stale-borrowed store; the next storeFor()
rebuilds it from the then-current manager.

**Return value**

- Type: `void`


---

### setStore() · <small>[🗎](../../src/Orm/EntityManager.php#L1082)</small>

`public function setStore(string $type, Azera\Orm\Storage\Store $store): static`

Register a Store under a TYPE NAME - the single routing axis.

Metadata `store` (#[Entity(store: ...)]) selects it per class.
A connection-owning backend with multiple clients registers one
type per client ('mongo-eu', 'mongo-us'): the type name IS the
discriminator - there is no role level.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$store` | [Store](Orm_Storage_Store.md) | - |  |

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
