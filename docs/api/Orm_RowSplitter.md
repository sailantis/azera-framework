# Class: RowSplitter

**Full name:** [Azera\Orm\RowSplitter](../../src/Orm/RowSplitter.php)

Executes a [`HydrationMap`](Orm_HydrationMap.md) against flat rows.

One row -> N entity instances (root + joined to-one relations), with
heap dedup (same PK = same object), LEFT JOIN orphan guard (all PK cols
NULL = no object), and to-many second queries executed on demand.

All reads bypass ResultSet entirely: raw assoc rows in, entities out.

## Public methods

### __construct() · <small>[🗎](../../src/Orm/RowSplitter.php#L16)</small>

`public function __construct(Azera\Orm\Heap $heap): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$heap` | [Heap](Orm_Heap.md) | - |  |

**Return value**

- Type: `mixed`


---

### split() · <small>[🗎](../../src/Orm/RowSplitter.php#L27)</small>

`public function split(array $row, array $plan): array`

Hydrate root + joined to-one entities from one flat row.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$row` | array | - | assoc row keyed by generated column aliases |
| `$plan` | array | - | HydrationMap::build() output |

**Return value**

- Type: `array`
- Description: root + by-relation



---

[Back to the Index ⤴](README.md)
