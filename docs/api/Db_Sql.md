# Class: Sql

**Full name:** [Azera\Db\Sql](../../src/Db/Sql.php)

SQL Value Object - Tagged Union for SQL Expressions

Represents SQL expressions (functions, casts, arrays, etc.) that serialize at SQL generation time.
Default behavior: serialize to literals (debug-friendly)
Sql::param() creates a named binding reference (:name) for use with Query::bind()

**Example**

```php
// Function with literals
Sql::func('concat', ['prefix_', 'value'])
// → concat('prefix_', 'value')

// Function with named binding reference (value supplied via Query::bind())
Sql::func('concat', ['prefix_', Sql::param('id')])
// → concat('prefix_', :id)

// PostgreSQL array
Sql::pgArray(['php', 'pgsql'])
// → '{"php","pgsql"}'

// Cast (driver-specific)
Sql::cast(Sql::column('text_search'), 'tsvector')
// PostgreSQL: text_search::tsvector
// MySQL: CAST(text_search AS tsvector)
```

## Public methods

### column() · <small>[🗎](../../src/Db/Sql.php#L80)</small>

`public static function column(string $name): static`

Column reference (unquoted identifier)
Supports Model.column syntax for automatic table resolution

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Column name (simple or Model.column format) |

**Return value**

- Type: `static`


---

### param() · <small>[🗎](../../src/Db/Sql.php#L96)</small>

`public static function param(string $name): static`

Named binding reference — emits :name in the SQL, resolved against
the manual bindings supplied via Query::bind().

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Parameter name (must match a key in bind()) |

**Return value**

- Type: `static`


---

### bind() · <small>[🗎](../../src/Db/Sql.php#L110)</small>

`public static function bind(string $name, mixed $value): static`

Bound parameter — emits :name in the SQL and propagates the value as a
real PDO named parameter (not inlined as an escaped literal).

The value is merged into Query::$subQueryBindings and reaches
Database::query() via PDO execute().

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Parameter name |
| `$value` | mixed | - | Parameter value |

**Return value**

- Type: `static`


---

### usesPdoBinding() · <small>[🗎](../../src/Db/Sql.php#L122)</small>

`public function usesPdoBinding(): bool`

Whether this node's bind parameters should be passed as real PDO named
parameters rather than inlined as escaped literals.

**Return value**

- Type: `bool`


---

### func() · <small>[🗎](../../src/Db/Sql.php#L133)</small>

`public static function func(string $name, array $args = []): static`

SQL function call

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name |
| `$args` | array | `[]` | Function arguments (scalars or Sql instances) |

**Return value**

- Type: `static`


---

### cast() · <small>[🗎](../../src/Db/Sql.php#L144)</small>

`public static function cast(mixed $value, string $type): static`

Type cast (driver-specific syntax)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | Value to cast (scalar or Sql) |
| `$type` | string | - | Target type name |

**Return value**

- Type: `static`


---

### pgArray() · <small>[🗎](../../src/Db/Sql.php#L154)</small>

`public static function pgArray(array $values): static`

PostgreSQL array literal

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | array | - | Array elements (scalars or Sql instances) |

**Return value**

- Type: `static`


---

### csList() · <small>[🗎](../../src/Db/Sql.php#L164)</small>

`public static function csList(array $values): static`

Comma-separated list (for IN clauses)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$values` | array | - | List elements (scalars or Sql instances) |

**Return value**

- Type: `static`


---

### raw() · <small>[🗎](../../src/Db/Sql.php#L175)</small>

`public static function raw(string $sql, array $inlineValues = []): static`

Raw SQL (unescaped, passed through as-is)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sql` | string | - | Raw SQL string |
| `$inlineValues` | array | `[]` | Optional values to be replaced in the SQL (e.g. for :name placeholders), treated as literal values (escaped) |

**Return value**

- Type: `static`


---

### value() · <small>[🗎](../../src/Db/Sql.php#L187)</small>

`public static function value(mixed $value): static`

Literal value (will be properly quoted/escaped)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | Value to serialize as SQL literal |

**Return value**

- Type: `static`


---

### json() · <small>[🗎](../../src/Db/Sql.php#L197)</small>

`public static function json(mixed $value): static`

JSON value (serialized as JSON literal)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - | Value to encode as JSON |

**Return value**

- Type: `static`


---

### concat() · <small>[🗎](../../src/Db/Sql.php#L209)</small>

`public static function concat(mixed ...$parts): static`

Driver-aware string concatenation
PostgreSQL/SQLite: uses || operator
MySQL: uses CONCAT() function

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$parts` | mixed | - | Parts to concatenate (scalars or Sql instances) |

**Return value**

- Type: `static`


---

### expr() · <small>[🗎](../../src/Db/Sql.php#L221)</small>

`public static function expr(mixed ...$parts): static`

Composite expression - concatenates parts with spaces
Useful for complex expressions like CASE WHEN
Plain strings are treated as raw SQL tokens (not serialized)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$parts` | mixed | - | Expression parts (strings are raw, use Sql instances for values) |

**Return value**

- Type: `static`


---

### case() · <small>[🗎](../../src/Db/Sql.php#L230)</small>

`public static function case(): Azera\Db\SqlCase`

CASE expression builder

**Return value**

- Type: [SqlCase](Db_SqlCase.md)
- Description: Fluent builder for CASE expressions


---

### subQuery() · <small>[🗎](../../src/Db/Sql.php#L240)</small>

`public static function subQuery(Azera\Db\Query $query): static`

Subquery expression - wraps a Query instance as a subquery

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$query` | [Query](Db_Query.md) | - | Subquery instance |

**Return value**

- Type: `static`


---

### as() · <small>[🗎](../../src/Db/Sql.php#L250)</small>

`public function as(string $alias): static`

Add alias to this expression (returns aliased node)

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$alias` | string | - | Column alias |

**Return value**

- Type: `static`


---

### getBindParams() · <small>[🗎](../../src/Db/Sql.php#L260)</small>

`public function getBindParams(): array`

Get bind parameters associated with this node

**Return value**

- Type: `array`
- Description: Associative array of bind parameters


---

### toSql() · <small>[🗎](../../src/Db/Sql.php#L320)</small>

`public function toSql(string $driver, callable $serialize, callable|null $protectIdentifier = null): string`

Serialize node to SQL string

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$driver` | string | - | Database driver (mysql, pgsql, sqlite) |
| `$serialize` | callable | - | Callback for serializing scalar values<br>Signature: fn(mixed $value, bool $param = false): string |
| `$protectIdentifier` | callable\|null | `null` | Callback for identifier resolution and quoting<br>Signature: fn(string $identifier, ?string $alias = null, int $mode = 0): string<br>If not provided, falls back to simple driver-based quoting |

**Return value**

- Type: `string`
- Description: SQL fragment


---

### __toString() · <small>[🗎](../../src/Db/Sql.php#L485)</small>

`public function __toString(): string`

**Return value**

- Type: `string`



---

[Back to the Index ⤴](README.md)
