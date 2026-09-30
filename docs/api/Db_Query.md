# Class: Query

**Full name:** [Azera\Db\Query](../../src/Db/Query.php)

Unified query builder for SELECT, INSERT, UPDATE, DELETE operations

**Example**

```php
// SELECT (raw/literal table)
$users = Query::raw()->table('users')->where('active', 1)->select();
$user = Query::raw()->table('users')->where('id', 5)->first();

// SELECT (model — resolves table, connection, and enables hydration)
$users = User::query()->where('status', 'active')->select();

// INSERT
Query::raw()->table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);

// UPSERT with ON CONFLICT/ON DUPLICATE KEY UPDATE
Query::raw()->table('users')->upsert(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

// UPDATE
Query::raw()->table('users')->where('id', 5)->update(['name' => 'Jane']);

// DELETE
Query::raw()->table('users')->where('id', 5)->delete();

// EXISTS / COUNT
$exists = Query::raw()->table('users')->where('email', 'test@example.com')->exists();
$count = Query::raw()->table('users')->where('active', 1)->count();
```

## Public methods

### __construct() · <small>[🗎](../../src/Db/Query.php#L148)</small>

`public function __construct(Azera\Db\Database|null $db = null): mixed`

Constructor. Can optionally pass a Database connection to use for this query.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$db` | [Database](Db_Database.md)\|null | `null` |  |

**Return value**

- Type: `mixed`


---

### new() · <small>[🗎](../../src/Db/Query.php#L158)</small>

`public static function new(Azera\Db\Database|null $db = null): static`

Factory method to create a new Query instance using the AppContext default resolver.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$db` | [Database](Db_Database.md)\|null | `null` |  |

**Return value**

- Type: `static`


---

### raw() · <small>[🗎](../../src/Db/Query.php#L170)</small>

`public static function raw(Azera\Db\Database|null $db = null): static`

Factory method to create a new Query instance that treats table names as literal
(no model/mapping resolution). Useful for raw queries, small scripts, or when
you want to avoid coupling to model classes.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$db` | [Database](Db_Database.md)\|null | `null` |  |

**Return value**

- Type: `static`


---

### modelFor() · <small>[🗎](../../src/Db/Query.php#L186)</small>

`public static function modelFor(string $modelClass, Azera\Db\Database|null $db = null): self`

Factory method for a MODEL-backed query with an explicit connection —
the test/CLI escape hatch for entities()/firstEntity() without a
bootstrapped model stack. Production code uses Model::query().

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$modelClass` | string | - |  |
| `$db` | [Database](Db_Database.md)\|null | `null` |  |

**Return value**

- Type: `self`


---

### using() · <small>[🗎](../../src/Db/Query.php#L199)</small>

`public function using(Azera\Db\Resolver\TableResolver $resolver): static`

Set a custom table resolver for this query. This is the low-level
escape hatch for custom resolver implementations.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$resolver` | [TableResolver](Db_Resolver_TableResolver.md) | - |  |

**Return value**

- Type: `static`


---

### table() · <small>[🗎](../../src/Db/Query.php#L264)</small>

`public function table(string $name, string|null $alias = null): static`

Set the table for this query. The name is resolved via the current
[`TableResolver`](Db_Resolver_TableResolver.md) to a concrete table source, schema, connection roles,
and optional model class for hydration.

The name may include an alias in `"table" AS "alias"` or `"table alias"` form.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Logical model/table name or model class name |
| `$alias` | string\|null | `null` | Optional table alias |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### from() · <small>[🗎](../../src/Db/Query.php#L291)</small>

`public function from(Azera\Db\Query|string $source, string|null $alias = null): static`

Set the source for this query from a subquery or raw table expression. The subquery will be wrapped in parentheses and treated as a table. An optional alias can be provided for the subquery.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$source` | [Query](Db_Query.md)\|string | - | Subquery or raw table expression |
| `$alias` | string\|null | `null` | Optional alias for the subquery |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### columns() · <small>[🗎](../../src/Db/Query.php#L321)</small>

`public function columns(array|string $columns): static`

Set columns for SELECT queries. Can be either a comma-separated string or an array of column names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$columns` | array\|string | - |  |

**Return value**

- Type: `static`


---

### limit() · <small>[🗎](../../src/Db/Query.php#L340)</small>

`public function limit(int $limit, int|null $offset = null): static`

Set the LIMIT and optional OFFSET for SELECT queries
(or limit number of rows affected for UPDATE/DELETE)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$limit` | int | - | Number of rows to limit |
| `$offset` | int\|null | `null` | Optional offset for the limit |

**Return value**

- Type: `static`


---

### offset() · <small>[🗎](../../src/Db/Query.php#L354)</small>

`public function offset(int $offset): static`

Sets an OFFSET clause for SELECT queries

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$offset` | int | - | Number of rows to offset |

**Return value**

- Type: `static`


---

### values() · <small>[🗎](../../src/Db/Query.php#L368)</small>

`public function values(object|array $values, bool $escape = true): static`

Adds values for INSERT or UPDATE queries. Can be either:
- An associative array of column => value pairs
- An object with public properties

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | object\|array | - |  |
| `$escape` | bool | `true` |  |

**Return value**

- Type: `static`


---

### bulkValues() · <small>[🗎](../../src/Db/Query.php#L393)</small>

`public function bulkValues(array $valuesList = [], bool $escape = true): static`

Set multiple rows of values for bulk insert operations.

Each item in the list should be an array of column => value pairs.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$valuesList` | array | `[]` |  |
| `$escape` | bool | `true` |  |

**Return value**

- Type: `static`


---

### hasValues() · <small>[🗎](../../src/Db/Query.php#L413)</small>

`public function hasValues(): bool`

Check if any values have been set for this query

**Return value**

- Type: `bool`


---

### set() · <small>[🗎](../../src/Db/Query.php#L427)</small>

`public function set(array|string $column, mixed $value = null, bool $escape = true): static`

Set a value for INSERT or UPDATE queries. Can be either:
- A single column name and value pair
- An associative array of column => value pairs

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$column` | array\|string | - |  |
| `$value` | mixed | `null` |  |
| `$escape` | bool | `true` |  |

**Return value**

- Type: `static`


---

### innerJoin() · <small>[🗎](../../src/Db/Query.php#L472)</small>

`public function innerJoin(Azera\Db\Query|string $model, Azera\Db\Condition|string|null $alias = null, Azera\Db\Condition|string|null $conditions = null): static`

Adds an INNER join to the query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [Query](Db_Query.md)\|string | - |  |
| `$alias` | [Condition](Db_Condition.md)\|string\|null | `null` |  |
| `$conditions` | [Condition](Db_Condition.md)\|string\|null | `null` |  |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### leftJoin() · <small>[🗎](../../src/Db/Query.php#L485)</small>

`public function leftJoin(Azera\Db\Query|string $model, Azera\Db\Condition|string|null $alias = null, Azera\Db\Condition|string|null $conditions = null): static`

Adds a LEFT join to the query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [Query](Db_Query.md)\|string | - |  |
| `$alias` | [Condition](Db_Condition.md)\|string\|null | `null` |  |
| `$conditions` | [Condition](Db_Condition.md)\|string\|null | `null` |  |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### rightJoin() · <small>[🗎](../../src/Db/Query.php#L498)</small>

`public function rightJoin(Azera\Db\Query|string $model, Azera\Db\Condition|string|null $alias = null, Azera\Db\Condition|string|null $conditions = null): static`

Adds a RIGHT join to the query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [Query](Db_Query.md)\|string | - |  |
| `$alias` | [Condition](Db_Condition.md)\|string\|null | `null` |  |
| `$conditions` | [Condition](Db_Condition.md)\|string\|null | `null` |  |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### crossJoin() · <small>[🗎](../../src/Db/Query.php#L511)</small>

`public function crossJoin(Azera\Db\Query|string $model, Azera\Db\Condition|string|null $alias = null, Azera\Db\Condition|string|null $conditions = null): static`

Adds a CROSS join to the query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [Query](Db_Query.md)\|string | - |  |
| `$alias` | [Condition](Db_Condition.md)\|string\|null | `null` |  |
| `$conditions` | [Condition](Db_Condition.md)\|string\|null | `null` |  |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### join() · <small>[🗎](../../src/Db/Query.php#L525)</small>

`public function join(Azera\Db\Query|string $model, Azera\Db\Condition|string|null $alias = null, Azera\Db\Condition|string|null $conditions = null, string|null $type = null): static`

Add a JOIN clause to the query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$model` | [Query](Db_Query.md)\|string | - |  |
| `$alias` | [Condition](Db_Condition.md)\|string\|null | `null` |  |
| `$conditions` | [Condition](Db_Condition.md)\|string\|null | `null` |  |
| `$type` | string\|null | `null` |  |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### orderBy() · <small>[🗎](../../src/Db/Query.php#L586)</small>

`public function orderBy(array|string $orderBy): static`

Set ORDER BY clause

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$orderBy` | array\|string | - |  |

**Return value**

- Type: `static`


---

### bind() · <small>[🗎](../../src/Db/Query.php#L607)</small>

`public function bind(object|array $bindParams): static`

Bind parameters for prepared statements. Can be either an associative array or an object with properties as parameter names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$bindParams` | object\|array | - |  |

**Return value**

- Type: `static`


---

### returnSql() · <small>[🗎](../../src/Db/Query.php#L621)</small>

`public function returnSql(bool $returnSql = true): static`

Set whether to return the SQL string instead of executing the query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$returnSql` | bool | `true` |  |

**Return value**

- Type: `static`


---

### distinct() · <small>[🗎](../../src/Db/Query.php#L636)</small>

`public function distinct(bool $distinct): static`

Set DISTINCT modifier for SELECT queries

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$distinct` | bool | - |  |

**Return value**

- Type: `static`


---

### injectBeforeColumns() · <small>[🗎](../../src/Db/Query.php#L647)</small>

`public function injectBeforeColumns(string $inject): static`

Set a string to be injected before the column list in SELECT queries (e.g. for SQL_CALC_FOUND_ROWS in MySQL)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$inject` | string | - |  |

**Return value**

- Type: `static`


---

### groupBy() · <small>[🗎](../../src/Db/Query.php#L658)</small>

`public function groupBy(array|string $groupBy): static`

Set GROUP BY clause

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$groupBy` | array\|string | - |  |

**Return value**

- Type: `static`


---

### forUpdate() · <small>[🗎](../../src/Db/Query.php#L671)</small>

`public function forUpdate(bool $forUpdate): static`

Sets a FOR UPDATE clause (MySQL/PostgreSQL) or FOR SHARE (PostgreSQL)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$forUpdate` | bool | - |  |

**Return value**

- Type: `static`


---

### sharedLock() · <small>[🗎](../../src/Db/Query.php#L682)</small>

`public function sharedLock(bool $sharedLock): static`

Sets a LOCK IN SHARE MODE / FOR SHARE clause (MySQL/PostgreSQL)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sharedLock` | bool | - |  |

**Return value**

- Type: `static`


---

### replace() · <small>[🗎](../../src/Db/Query.php#L697)</small>

`public function replace(bool $replace = true): static`

Mark this as a REPLACE INTO operation (MySQL/SQLite)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$replace` | bool | `true` |  |

**Return value**

- Type: `static`


---

### ignore() · <small>[🗎](../../src/Db/Query.php#L708)</small>

`public function ignore(bool $ignore = true): static`

Set IGNORE modifier for INSERT (MySQL/SQLite) or ON CONFLICT DO NOTHING (PostgreSQL)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ignore` | bool | `true` |  |

**Return value**

- Type: `static`


---

### updateValues() · <small>[🗎](../../src/Db/Query.php#L731)</small>

`public function updateValues(array $updateValues, bool $escape = true): static`

Set values for ON CONFLICT/ON DUPLICATE KEY UPDATE clause. Can be either:
- List array -> EXCLUDED/VALUES mode
- Assoc array -> explicit values

When omitted entirely, `compileInsert()` derives the SET clause
from the INSERT columns ("col"=EXCLUDED."col" on sqlite/pgsql,
col=VALUES(col) on mysql) with the conflict target excluded — the
fast in-place-update shape. Passing the PK in explicit update values
forces SQLite to compile the conflict action as an internal
DELETE+INSERT (fsync-bound), so explicit PK assignments are the
one thing to avoid.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$updateValues` | array | - |  |
| `$escape` | bool | `true` |  |

**Return value**

- Type: `static`


---

### conflict() · <small>[🗎](../../src/Db/Query.php#L762)</small>

`public function conflict(array|string $columnsOrConstraint): static`

Set conflict target for ON CONFLICT clause (PostgreSQL). Can be either:
- Array with column names
- String with column names or constraint name

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$columnsOrConstraint` | array\|string | - |  |

**Return value**

- Type: `static`


---

### returning() · <small>[🗎](../../src/Db/Query.php#L774)</small>

`public function returning(array|string|null $columns): static`

Set columns to return from an INSERT/UPDATE/DELETE query. Supported by PostgreSQL (RETURNING) and MySQL (RETURNING with MySQL 8.0.27+)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$columns` | array\|string\|null | - |  |

**Return value**

- Type: `static`

**Throws**

- Exception


---

### with() · <small>[🗎](../../src/Db/Query.php#L796)</small>

`public function with(string $relation): static`

Eager-load a relation (Phase 5 ORM path). Relation names must be
declared via Orm attributes on the model. BelongsTo/HasOne become
LEFT JOINs at select() time (one SQL, alias-separated rows); HasMany
stays a second query by parent IDs.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$relation` | string | - |  |

**Return value**

- Type: `static`


---

### toSql() · <small>[🗎](../../src/Db/Query.php#L813)</small>

`public function toSql(): string`

Compile and return the SQL string for this query without executing it

**Return value**

- Type: `string`

**Throws**

- Exception


---

### select() · <small>[🗎](../../src/Db/Query.php#L827)</small>

`public function select(array|string|null $columns = null): Azera\Db\ResultSet|Azera\Orm\JoinedResultSet|string`

Execute SELECT query and return ResultSet or return SQL string if returnSql is enabled

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$columns` | array\|string\|null | `null` | Columns to select, or null to ignore parameter. Can be either a comma-separated string or an array of column names. |

**Return value**

- Type: [ResultSet](Db_ResultSet.md)|[JoinedResultSet](Orm_JoinedResultSet.md)|`string`
- Description: ResultSet normally, JoinedResultSet on the eager-load path, string when returnSql is true

**Throws**

- Exception


---

### entities() · <small>[🗎](../../src/Db/Query.php#L939)</small>

`public function entities(): array`

ORM hydration terminal: execute the SELECT and hydrate raw rows into
heap-tracked entities via the per-class FastHydrator plan.

This is the unified read path — the same builder that serves raw
tables (Query::raw) and FETCH_CLASS ResultSets (select()) also serves
identity-mapped entities here. All hydration runs on the request-
scoped heap (AppContext::heap()), so the same row read twice in one
request yields the SAME object and the EntityManager sees it MANAGED.

Requires model mode (resolved modelClass); raw-table queries throw.
Explicit columns() are honored: unknown names surface as SQL errors,
while known-but-aliased columns hydrate what they provide.

**Return value**

- Type: `array`

**Throws**

- Exception|\LogicException


---

### firstEntity() · <small>[🗎](../../src/Db/Query.php#L971)</small>

`public function firstEntity(): object|null`

First matching row as a heap-tracked entity, or null. LIMIT 1,
offset cleared — same semantics as the criteria terminals, zero
extra terminal methods on the builder.

**Return value**

- Type: `object`|`null`

**Throws**

- Exception|\LogicException


---

### fresh() · <small>[🗎](../../src/Db/Query.php#L996)</small>

`public function fresh(bool $fresh = true): static`

Fresh-read mode for the ORM hydration terminals.

The identity map's staleness escape: with fresh() enabled,
entities()/firstEntity() re-read rows and refresh identity-map hits
IN PLACE — the same objects, current values (no stale repeats, no
duplicate instances). Scheduled (unflushed) entities keep their
pending state until flush(). For PK reads prefer
{@see \Azera\Orm\EntityManager::find($class, $id, fresh: true)} /
Model::find($id, fresh: true); fresh() serves criteria reads.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$fresh` | bool | `true` |  |

**Return value**

- Type: `static`


---

### first() · <small>[🗎](../../src/Db/Query.php#L1080)</small>

`public function first(): Azera\Orm\Model|string|null`

Execute SELECT query and return the first heap-tracked entity or null
(or the SQL string when returnSql is enabled).

Same hydration path as entities()/Model::find() — identity-mapped,
metadata-mapped columns, bound parameters.

**Return value**

- Type: [Model](Orm_Model.md)|`string`|`null`
- Description: First entity, or SQL string, or null if no results

**Throws**

- Exception


---

### insert() · <small>[🗎](../../src/Db/Query.php#L1100)</small>

`public function insert(array|null $data = null): Azera\Db\ResultSet|array|string|bool`

Execute INSERT or UPSERT query or return SQL string if returnSql is enabled

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array\|null | `null` | Data to insert |

**Return value**

- Type: [ResultSet](Db_ResultSet.md)|`array`|`string`|`bool`
- Description: Insert ID, true on success, or SQL string, or result of returning clause

**Throws**

- Exception


---

### upsert() · <small>[🗎](../../src/Db/Query.php#L1111)</small>

`public function upsert(array|null $data = null): Azera\Db\ResultSet|array|string|bool`

Execute UPSERT query (INSERT with ON CONFLICT/ON DUPLICATE KEY UPDATE) or return SQL string if returnSql is enabled

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array\|null | `null` | Data to insert |

**Return value**

- Type: [ResultSet](Db_ResultSet.md)|`array`|`string`|`bool`
- Description: Insert ID, true on success, or SQL string, or result of returning clause

**Throws**

- Exception


---

### update() · <small>[🗎](../../src/Db/Query.php#L1150)</small>

`public function update(array|null $data = null): Azera\Db\ResultSet|array|string|int`

Execute UPDATE query or return SQL string if returnSql is enabled

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$data` | array\|null | `null` | Data to update |

**Return value**

- Type: [ResultSet](Db_ResultSet.md)|`array`|`string`|`int`
- Description: Number of affected rows or SQL string, or row of returning clause

**Throws**

- Exception


---

### delete() · <small>[🗎](../../src/Db/Query.php#L1180)</small>

`public function delete(): Azera\Db\ResultSet|array|string|int`

Execute DELETE query

**Return value**

- Type: [ResultSet](Db_ResultSet.md)|`array`|`string`|`int`
- Description: Number of affected rows, SQL string, or result of returning clause

**Throws**

- Exception


---

### truncate() · <small>[🗎](../../src/Db/Query.php#L1205)</small>

`public function truncate(): string|int`

Execute TRUNCATE query or return SQL string if returnSql is enabled

**Return value**

- Type: `string`|`int`
- Description: Number of affected rows or SQL string

**Throws**

- Exception


---

### exists() · <small>[🗎](../../src/Db/Query.php#L1226)</small>

`public function exists(): string|bool`

Check if any rows exist matching the query

**Return value**

- Type: `string`|`bool`

**Throws**

- Exception


---

### count() · <small>[🗎](../../src/Db/Query.php#L1253)</small>

`public function count(): string|int`

Count rows matching the query

**Return value**

- Type: `string`|`int`
- Description: Number of matching rows or SQL string

**Throws**

- Exception


---

### getBindings() · <small>[🗎](../../src/Db/Query.php#L2000)</small>

`public function getBindings(): array`

Get bind parameters

**Return value**

- Type: `array`


---

### paginate() · <small>[🗎](../../src/Db/Query.php#L2012)</small>

`public function paginate(int $page = 1, int $pageSize = 30, bool $reverse = false): Azera\Db\Paginator`

Create a paginator for the current query

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$page` | int | `1` | Page number (1-based) |
| `$pageSize` | int | `30` | Number of items per page |
| `$reverse` | bool | `false` | Whether to reverse the order of results (for efficient deep pagination) |

**Return value**

- Type: [Paginator](Db_Paginator.md)


---

### getRowCount() · <small>[🗎](../../src/Db/Query.php#L2051)</small>

`public function getRowCount(): int`

Return the number of affected rows for write operations or the number of rows in the result set for read operations

**Return value**

- Type: `int`



---

[Back to the Index ⤴](README.md)
