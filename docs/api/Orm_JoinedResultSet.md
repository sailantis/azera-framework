# Class: JoinedResultSet

**Full name:** [Azera\Orm\JoinedResultSet](../../src/Orm/JoinedResultSet.php)

Result of an eager-load (with()) read.

Executes the joined SQL once (raw rows), splits rows via RowSplitter,
and materializes to-many relations with ONE batched second query per
relation: `WHERE fk IN (rootIds)`, grouped and assigned. This is the
Cycle INLOAD / Eloquent-proven strategy — joining to-many would
duplicate parent rows, so it never does.

All entities hydrate into the REQUEST-SCOPED heap (AppContext::heap()),
so a parent joined across many rows — or read again later in the same
request — is the same object instance, and to-many children attach to
the same identity map the EntityManager uses.

Deliberately NOT a ResultSet: no FETCH_CLASS double-write, no wrapper
cursor; iteration yields root entities with relations attached.

## Public methods

### __construct() · <small>[🗎](../../src/Orm/JoinedResultSet.php#L31)</small>

`public function __construct(array $rows, array $plan, Azera\Db\Database|null $db = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rows` | array | - | raw rows with alias-separated columns |
| `$plan` | array | - | HydrationMap::build() output |
| `$db` | [Database](Db_Database.md)\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### getIterator() · <small>[🗎](../../src/Orm/JoinedResultSet.php#L39)</small>

`public function getIterator(): Generator`

**Return value**

- Type: `Generator`


---

### first() · <small>[🗎](../../src/Orm/JoinedResultSet.php#L149)</small>

`public function first(): object|null`

First root entity or null.

**Return value**

- Type: `object`|`null`



---

[Back to the Index ⤴](README.md)
