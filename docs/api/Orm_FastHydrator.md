# Class: FastHydrator

**Full name:** [Azera\Orm\FastHydrator](../../src/Orm/FastHydrator.php)

Per-class compiled hydration plan.

The generic HydrationMap + RowSplitter path re-walks the metadata arrays
for EVERY row and entity: `foreach ($meta['columns'] as ...)` twice plus
per-field array_key_exists checks. This class compiles the same plan into
flat scalar arrays (field names, column aliases, PK aliases) ONCE per
class, so hydrating a row is: one heap lookup, one instantiation, two
tightly-typed copy loops over plain lists — no metadata array walking.

Same contract as HydrationMap::build() + RowSplitter::split() for the
single-root (no relations) case, which is the hot path for list reads.
Relations keep using the generic path (they are per-row by nature).

REFLECTION-FREE: every decision this class needs (column names, PK,
cast policy, nullability) is compiled into metadata by
Metadata::compile() — the ONE place properties are reflected. This
class only reshapes those arrays into paired lists.

L1-cached per class like Metadata; nothing else to configure.

## Public Properties

- `public` string `$class` · <small>[🗎](../../src/Orm/FastHydrator.php)</small>
- `public` array `$fields` · <small>[🗎](../../src/Orm/FastHydrator.php)</small>
- `public` array `$columns` · <small>[🗎](../../src/Orm/FastHydrator.php)</small>
- `public` array `$pkFields` · <small>[🗎](../../src/Orm/FastHydrator.php)</small>
- `public` array `$pkColumns` · <small>[🗎](../../src/Orm/FastHydrator.php)</small>

## Public methods

### for() · <small>[🗎](../../src/Orm/FastHydrator.php#L122)</small>

`public static function for(string $class): self`

Per-class singleton plan (mirrors Metadata::for semantics).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |

**Return value**

- Type: `self`


---

### hydrate() · <small>[🗎](../../src/Orm/FastHydrator.php#L151)</small>

`public function hydrate(Azera\Orm\Heap $heap, array $row, bool $fresh = false): array`

Compile a row -> [entity, id, snapshotData] triple.

Identity-map probe FIRST: with the shared request-scoped heap, the
same row read twice in one request MUST yield the same object (a
per-query heap never faced this because it died with the query).
A hit returns the existing instance untouched — the heap snapshot
stays authoritative and in-request mutations are not clobbered.

$fresh=true inverts the hit behavior for STALE-READ-SENSITIVE reads:
the tracked instance is refreshed IN PLACE from the row (`apply()`)
— same object, current values. Entities with scheduled (unflushed)
writes keep their pending state; the DB never clobbers queued work.

Cold path: build id + entity + snapshot in three tight list loops,
attach once.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$heap` | [Heap](Orm_Heap.md) | - |  |
| `$row` | array | - | raw assoc row keyed by COLUMN name |
| `$fresh` | bool | `false` |  |

**Return value**

- Type: `array`


---

### apply() · <small>[🗎](../../src/Orm/FastHydrator.php#L262)</small>

`public function apply(object $entity, Azera\Orm\Node $node, array $row): void`

Refresh an EXISTING tracked entity in place from a fresh store row.

Where the identity-map contract (one row = one object) meets the
freshness requirement: instead of materializing a second instance,
the row values are applied onto the live entity and the node
snapshot is updated to match — the new diff baseline. Values go
through the same `put()` gate as cold hydration, so the
refresh path cannot drift from the read path.

Only columns present in $row are touched; entity and snapshot keep
their previous values for the rest (partial rows — explicit
columns() — stay consistent). Node state is NOT touched: callers
guarantee the entity is not scheduled.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |
| `$node` | [Node](Orm_Node.md) | - |  |
| `$row` | array | - |  |

**Return value**

- Type: `void`


---

### attach() · <small>[🗎](../../src/Orm/FastHydrator.php#L301)</small>

`public function attach(Azera\Orm\Heap $heap, object $entity, array $id, array $data): Azera\Orm\Node`

Attach a hydrated entity to the heap as MANAGED.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$heap` | [Heap](Orm_Heap.md) | - |  |
| `$entity` | object | - |  |
| `$id` | array | - |  |
| `$data` | array | - |  |

**Return value**

- Type: [Node](Orm_Node.md)


---

### put() · <small>[🗎](../../src/Orm/FastHydrator.php#L340)</small>

`public function put(object $entity, string $field, mixed $raw): mixed`

Decode one raw store value and put it on the entity, honoring the
compiled plan; returns the value the caller should record in the
node SNAPSHOT (the store representation).

This is the ONE place store values become property values, used by
hydration AND every write-back path (fresh refresh, RETURNING rows,
id backfill, revert, joins) — the mirror of
EntityManager::extractData()'s single encode point. Centralizing it
is what keeps a snapshot diff-clean by construction: the snapshot is
read back OFF the property, so it always matches what extractData()
will produce for that entity. A `cast: false` typed column (PHP
coerces the driver's string on assignment) therefore cannot pick up
a phantom UPDATE, and a NULL cannot slip past the nullability
contract.

The cast and the null gate come from THIS hydrator's compiled tables
(resolved once from metadata in the constructor), so callers pass no
metadata and this method never consults Metadata or reflection.

NULL handling, from the compiled `nullable` flag ALONE:

- column NOT nullable → throw a diagnosable LogicException. The raw
  TypeError this replaces fires at the assignment with no hint of
  which column, row, or remedy is at fault.
- column nullable → assign the null. Safe with no second check:
  resolveNullable() already rejected the one shape whose property
  could not hold it, so the flag implies the property accepts null.

A non-null value is always assigned; PHP's weak mode coerces a
numeric string, and the cast has already decoded what needed it.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |
| `$field` | string | - |  |
| `$raw` | mixed | - |  |

**Return value**

- Type: `mixed`


---

### clear() · <small>[🗎](../../src/Orm/FastHydrator.php#L367)</small>

`public static function clear(): void`

Forget all compiled plans (tests).

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
