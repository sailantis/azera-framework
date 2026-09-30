# Class: Heap

**Full name:** [Azera\Orm\Heap](../../src/Orm/Heap.php)

Identity map: ONE node per persisted entity, keyed by class + PK values.

Purpose is CORRECTNESS, not caching: the same DB row loaded twice in one
request yields the same object, and the EntityManager diffs against the
node snapshot instead of scanning every constructed instance. The heap is
request-scoped — `resetState()` wipes everything between requests in
persistent workers (non-negotiable; a leaking heap would return stale
entities for a new request / another tenant).

Performance shape: two flat array lookups per access (identity index and
oid index), no objects allocated for lookups.

## Public methods

### key() · <small>[🗎](../../src/Orm/Heap.php#L77)</small>

`public static function key(string $class, array $id): string`

Build the composite identity key for an entity.

Assoc id arrays are canonicalized (ksort) so ['a'=>1,'b'=>2] and
['b'=>2,'a'=>1] produce the SAME key. List-form id arrays keep their
value order (position defines the field).

Fast path: single-PK scalar ids (the overwhelmingly common shape —
e.g. ['id' => 42]) skip ksort/array_is_list and build the key with
one interpolation. Composite keys keep the full canonicalization.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - | PK field => value (all values non-null) |

**Return value**

- Type: `string`


---

### attach() · <small>[🗎](../../src/Orm/Heap.php#L101)</small>

`public function attach(object $entity, Azera\Orm\Node $node): void`

Register (or replace) the node for an entity.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |
| `$node` | [Node](Orm_Node.md) | - |  |

**Return value**

- Type: `void`


---

### find() · <small>[🗎](../../src/Orm/Heap.php#L165)</small>

`public function find(object $entity): Azera\Orm\Node|null`

Find the node for an entity OBJECT (regardless of its identity).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: [Node](Orm_Node.md)|`null`


---

### findById() · <small>[🗎](../../src/Orm/Heap.php#L176)</small>

`public function findById(string $class, array $id): Azera\Orm\Node|null`

Find the node for a class + PK values — the identity-map hit path.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - |  |

**Return value**

- Type: [Node](Orm_Node.md)|`null`


---

### detach() · <small>[🗎](../../src/Orm/Heap.php#L184)</small>

`public function detach(object $entity): void`

Drop an entity from identity tracking (after delete or detach).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$entity` | object | - |  |

**Return value**

- Type: `void`


---

### scheduled() · <small>[🗎](../../src/Orm/Heap.php#L211)</small>

`public function scheduled(): array`

All nodes currently scheduled for a flush, in insertion order.

**Return value**

- Type: `array`


---

### entityFor() · <small>[🗎](../../src/Orm/Heap.php#L228)</small>

`public function entityFor(Azera\Orm\Node $node): object|null`

Resolve the entity object a node was attached with (flush-time
backfill needs the actual instance, not just its bookkeeping node).

O(1) via the reverse node => oid index.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$node` | [Node](Orm_Node.md) | - |  |

**Return value**

- Type: `object`|`null`


---

### all() · <small>[🗎](../../src/Orm/Heap.php#L240)</small>

`public function all(): array`

All nodes (any state), in insertion order.

**Return value**

- Type: `array`


---

### count() · <small>[🗎](../../src/Orm/Heap.php#L245)</small>

`public function count(): int`

**Return value**

- Type: `int`


---

### resetState() · <small>[🗎](../../src/Orm/Heap.php#L255)</small>

`public function resetState(): void`

Request-scoped hook: wipe the entire identity map. Called between
requests in persistent workers. This is a correctness requirement —
never turn the heap into a cross-request cache.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
