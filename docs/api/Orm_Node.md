# Class: Node

**Full name:** [Azera\Orm\Node](../../src/Orm/Node.php)

A single entity's bookkeeping entry in the [`Heap`](Orm_Heap.md).

Mirrors Cycle's Node concept, trimmed to what the EntityManager's write
pipeline needs:
the entity reference, its identity (PK values), a data snapshot for
dirty diffing, and the persistence lifecycle state.

The data array holds SCALAR row values only (the raw store representation) —
not PHP objects. Objects would break both the diff and the L2 cache story.

## Public Constants

- **NEW** = `1`
- **MANAGED** = `2`
- **SCHEDULED_INSERT** = `3`
- **SCHEDULED_UPDATE** = `4`
- **SCHEDULED_DELETE** = `5`
- **DELETED** = `6`
- **SCHEDULED_UPSERT** = `7`

## Public Properties

- `public readonly` string `$class` · <small>[🗎](../../src/Orm/Node.php)</small>
- `public readonly` array `$id` · <small>[🗎](../../src/Orm/Node.php)</small>
- `public` array `$data` · <small>[🗎](../../src/Orm/Node.php)</small>
- `public` int `$state` · <small>[🗎](../../src/Orm/Node.php)</small>
- `public` array `$changedFields` · <small>[🗎](../../src/Orm/Node.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Node.php#L39)</small>

`public function __construct(string $class, array $id, array $data, int $state = 1, array $changedFields = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |
| `$id` | array | - |  |
| `$data` | array | - |  |
| `$state` | int | `1` |  |
| `$changedFields` | array | `[]` |  |

**Return value**

- Type: `mixed`


---

### isScheduled() · <small>[🗎](../../src/Orm/Node.php#L47)</small>

`public function isScheduled(): bool`

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
