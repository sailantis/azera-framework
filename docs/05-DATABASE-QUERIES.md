# Database Queries

**The query builder** – SELECT, INSERT, UPDATE and DELETE through one fluent API.

Azera uses a unified fluent query builder: `Azera\Db\Query`.
Access it via `Query::raw()` (literal tables) or `Query::new()` (model/mapping
resolution via the AppContext-registered `TableResolver`), or through models
with `Model::query()`.

## Basic Setup

```php
use Azera\AppContext;
use Azera\Db\Database;

AppContext::instance()->dbManager()->set('default', new Database(
    'mysql:host=localhost;dbname=myapp',
    'user',
    'pass'
));
```

## Query Entry Points

```php
use Azera\Db\Query;

// Plain table (literal — no model resolution)
$q = Query::raw()->table('users');

// Model class (resolves table, connection, and enables hydration)
$users = User::query()->where('status', 'active')->select();
```

> **See also:** [Table Resolvers](#table-resolvers) below for using model mappings without model classes, or custom resolver implementations.

## SELECT

All queries use prepared statements. Chain methods to add conditions, joins, sorting, and pagination.

```php
$users = Query::raw()->table('users', 'u')
    ->columns(['u.id', 'u.username', 'u.email'])
    ->where('u.created_at >', '2024-01-01')
    ->where('u.status', 'active')
    ->orderBy('u.created_at DESC')
    ->limit(20)
    ->offset(0)
    ->select();

$user = Query::raw()->table('users')
    ->where('id', 5)
    ->first();

// DISTINCT
$emails = Query::raw()->table('orders')
    ->columns(['customer_email'])
    ->distinct(true)
    ->select();
```

## WHERE Styles

Three equivalent styles — all safe (prepared statements behind the scenes):

```php
// Condition + inline values (values are escaped and inserted into SQL)
User::query()->where('email = :email', ['email' => 'a@example.com'])->first();

// Condition + bound parameters (values remain real PDO parameters)
User::query()->where('email = :email')->bind(['email' => 'a@example.com'])->first();

// Column/value pair – shorthand for WHERE email = 'a@example.com'
User::query()->where('email', 'a@example.com')->first();

// Column/value pair with explicit operator
User::query()->where('email <>', 'a@example.com')->first();

// OR condition
User::query()
    ->where('status', 'active')
    ->orWhere('status', 'pending')
    ->select();
```

## Condition Grouping

Use `group()` with a callback to wrap parts of a WHERE clause in parentheses. The `orGroup()`, `notGroup()`, and `orNotGroup()` variants prefix the group with `OR`, `NOT`, or `OR NOT` respectively.

```php
// WHERE (status = 'active') AND (role = 'admin' OR role = 'moderator')
User::query()
    ->where('status', 'active')
    ->group(
        fn(Condition $c) =>
            $c->where('role', 'admin')
                ->orWhere('role', 'moderator')
    )
    ->select();

// WHERE (status = 'active') AND NOT (role = 'admin' OR role = 'moderator')
User::query()
    ->where('status', 'active')
    ->notGroup(
        fn(Condition $c) =>
            $c->where('role', 'admin')
                ->orWhere('role', 'moderator')
    )
    ->select();
```

## IN / NOT IN

`inWhere()` and `notInWhere()` generate `IN (…)` / `NOT IN (…)` clauses. They also accept another `Query` instance to produce a subquery.

```php
// Simple value list
User::query()->inWhere('status', ['active', 'pending'])->select();
User::query()->notInWhere('role', ['guest', 'banned'])->select();

// OR variant
User::query()
    ->where('department', 'sales')
    ->orInWhere('role', ['admin', 'manager'])
    ->select();

// Subquery as value source
$activeIds = User::query()->columns('id')->where('status', 'active');
Post::query()->inWhere('user_id', $activeIds)->select();
```

## BETWEEN

```php
// WHERE created_at BETWEEN '2025-01-01' AND '2025-12-31'
User::query()->betweenWhere('created_at', '2025-01-01', '2025-12-31')->select();

// WHERE NOT (age BETWEEN 18 AND 65)
User::query()->notBetweenWhere('age', 18, 65)->select();

// OR variant
User::query()
    ->where('status', 'active')
    ->orBetweenWhere('score', 90, 100)
    ->select();
```

## LIKE

```php
// WHERE username LIKE 'john%'
User::query()->likeWhere('username', 'john%')->select();

// WHERE username NOT LIKE '%bot%'
User::query()->notLikeWhere('username', '%bot%')->select();

// OR LIKE / OR NOT LIKE
User::query()
    ->where('status', 'active')
    ->orLikeWhere('email', '%@example.com')
    ->select();
```

## FROM Subquery

Use a `Query` instance as the table source for a derived table. The subquery is wrapped in parentheses and its bind parameters are automatically merged into the parent query.

```php
use Azera\Db\Query;

// Build the inner query independently
$recent = Query::raw()->table('orders')
    ->where('created_at > :since', ['since' => '2025-01-01'])
    ->columns(['user_id', 'total']);

// Use it as a derived table with an alias
$results = Query::raw()
    ->from($recent, 'recent_orders')
    ->where('recent_orders.total >', 100)
    ->select();
// Produces: SELECT * FROM (SELECT `user_id`, `total` FROM `orders` WHERE ...) AS `recent_orders` WHERE ...
```

`from()` also accepts a plain table name string (same as `table()`):

```php
// Equivalent: plain string still works
$q = Query::raw()->from('users', 'u')->where('u.status', 'active')->select();
```

## JOIN, GROUP, HAVING

```php
$rows = Query::raw()->table('posts', 'p')
    ->columns([
        'p.id',
        'p.title',
        'u.username',
        'COUNT(c.id) AS comments_count',
    ])
    ->join('users', 'u', 'u.id = p.user_id')
    ->leftJoin('comments', 'c', 'c.post_id = p.id')
    ->where('p.status', 'published')
    ->groupBy('p.id')
    ->having('COUNT(c.id) > :min', ['min' => 0])
    ->orderBy('comments_count DESC')
    ->select();
```

### Subquery in JOIN

Any join method (`join`, `innerJoin`, `leftJoin`, `rightJoin`, `crossJoin`) also accepts a `Query` instance as the first argument. Supply an alias as the second argument so the outer query can reference it.

```php
// Pre-aggregate orders into a subquery
$orderTotals = Query::raw()->table('orders')
    ->where('status', 'completed')
    ->groupBy('user_id')
    ->columns(['user_id', 'SUM(total) AS total_spent']);

$results = Query::raw()->table('users', 'u')
    ->leftJoin($orderTotals, 'ot', 'ot.user_id = u.id')
    ->columns(['u.username', 'ot.total_spent'])
    ->where('ot.total_spent >', 500)
    ->select();
// Produces: SELECT ... FROM `users` AS `u`
//   LEFT JOIN (SELECT `user_id`, SUM(total) AS total_spent
//              FROM `orders` WHERE ... GROUP BY `user_id`) AS `ot` ON (ot.user_id = u.id)
//   WHERE ...
```

Bind parameters from the subquery are automatically propagated to the parent query — you never need to merge them manually.

## INSERT / UPSERT / UPDATE / DELETE

INSERT returns the new ID, UPDATE and DELETE return affected row counts.

```php
// INSERT
$id = User::query()->insert([
    'username' => 'john',
    'email' => 'john@example.com',
]);

// INSERT with bound parameters
$id = User::query()->bind([
    'username' => 'john',
    'email' => 'john@example.com',
])->insert();

// UPSERT
User::query()->upsert([
    'id' => 1,
    'username' => 'john',
    'email' => 'john@example.com',
]);

// UPSERT with bound parameters
User::query()->bind([
    'id' => 1,
    'username' => 'john',
    'email' => 'john@example.com',
])->upsert();

// UPDATE
$affected = User::query()
    ->where('id', 1)
    ->update(['email' => 'john.new@example.com']);

// UPDATE with bound parameters
$affected = User::query()
    ->where('id', 1)
    ->bind(['email' => 'john.new@example.com'])
    ->update();

// DELETE
$deleted = User::query()
    ->where('status', 'inactive')
    ->delete();

// DELETE with bound parameters
$deleted = User::query()
    ->where('status = :status')
    ->bind(['status' => 'inactive'])
    ->delete();
```

#### Upsert SET shape

Without `updateValues()`, the `ON CONFLICT` / `ON DUPLICATE KEY UPDATE` clause
is **derived** from the INSERT columns. Each non-conflict-target column uses the
attempted row: `"col"=EXCLUDED."col"` (SQLite/PostgreSQL) or `col=VALUES(col)`
(MySQL). The conflict target—explicit `conflict()` columns or the model's
primary key—is not written in the `SET` clause.

```php
// SQLite: INSERT INTO "users" ("id","email") VALUES (1, 'john@example.com')
//         ON CONFLICT DO UPDATE SET "email"=EXCLUDED."email"
User::query()->upsert(['id' => 1, 'email' => 'john@example.com']);
```

> **Performance note (SQLite):** writing the primary key (or rebinding literal
> values) into the SET clause forces SQLite to compile the conflict action as an
> internal DELETE+INSERT, which is fsync-bound (~1.2–5.7 ms vs ~8 µs per
> statement on WAL SQLite). The derived EXCLUDED shape compiles as an in-place
> update — use explicit `updateValues()` only for custom expressions.

### Bulk INSERT

Insert multiple rows in a single statement with `bulkValues()`:

```php
Query::raw()->table('tags')->bulkValues([
    ['name' => 'php'],
    ['name' => 'mysql'],
    ['name' => 'redis'],
])->insert();
```

### INSERT IGNORE / REPLACE INTO

`ignore()` silently skips duplicate-key violations. `replace()` uses `REPLACE INTO` (MySQL/SQLite), which deletes the conflicting row and re-inserts.

```php
// INSERT IGNORE INTO (MySQL/SQLite) / ON CONFLICT DO NOTHING (PostgreSQL)
User::query()->ignore()->insert(['email' => 'existing@example.com', 'username' => 'john']);

// REPLACE INTO (MySQL/SQLite only)
User::query()->replace()->insert(['id' => 1, 'email' => 'updated@example.com']);
```

### RETURNING clause (PostgreSQL / MySQL 8.0.27+ / MariaDB 10.5.0+ / SQLite 3.35+)

Chain `returning()` on INSERT, UPDATE, or DELETE to get column values back from the database:

```php
// Insert and retrieve the generated id and created_at in one round-trip
$row = User::query()
    ->returning(['id', 'created_at'])
    ->insert(['username' => 'alice', 'email' => 'alice@example.com']);

// Update and get the new value back
$row = User::query()
    ->where('id', 5)
    ->returning('updated_at')
    ->update(['status' => 'active']);
```

### TRUNCATE

```php
Query::raw()->table('cache_entries')->truncate();
```

## EXISTS / COUNT

```php
// Simple where with inline value
$exists = User::query()->where('email', 'john@example.com')->exists();
$total = User::query()->where('status', 'active')->count();

// With bound parameters
$exists = User::query()->where('email = :email')->bind(['email' => 'john@example.com'])->exists();
$total = User::query()->where('status = :status')->bind(['status' => 'active'])->count();
```

> **Note:** The query builder terminal method is `count()`. The static model helper `Model::count()` is a thin wrapper around it.

```php
// Model-level count
$active = User::count(['status' => 'active']);
```

## Locking

Use `forUpdate()` for pessimistic write locks and `sharedLock()` for shared/read locks. Both are supported on MySQL and PostgreSQL.

```php
// SELECT … FOR UPDATE (exclusive lock)
$user = Query::raw()->table('users')
    ->where('id', 5)
    ->forUpdate(true)
    ->first();

// SELECT … LOCK IN SHARE MODE (MySQL) / FOR SHARE (PostgreSQL)
$user = Query::raw()->table('users')
    ->where('id', 5)
    ->sharedLock(true)
    ->first();
```

## Pagination with Paginator

Use `Azera\Db\Paginator` to paginate any query builder. The paginator runs a count() query first, then fetches the requested page using `LIMIT/OFFSET`.

```php
$paginator = User::query()
    ->where('status', 'active')
    ->orderBy('created_at DESC')
    ->paginate(page: 2, pageSize: 20);

$users = $paginator->entities(); // identity-mapped User instances for page 2

$meta = [
    'currentPage' => $paginator->currentPage(),
    'previousPage' => $paginator->previousPage(),
    'nextPage' => $paginator->nextPage(),
    'lastPage' => $paginator->lastPage(),
    'pageSize' => $paginator->pageSize(),
    'totalItems' => $paginator->totalItems(),
    'firstItem' => $paginator->firstItem(),
    'lastItem' => $paginator->lastItem(),
];
```

Fetch terminals (each executes the page query exactly once, after the COUNT):

| Terminal       | Returns                                                                                |
| -------------- | -------------------------------------------------------------------------------------- |
| `entities()`   | Identity-mapped model instances (ORM hydration — see [Models & ORM](04-MODELS-ORM.md)) |
| `objects()`    | Plain `stdClass` rows                                                                  |
| `assoc()`      | Associative arrays                                                                     |
| `fetch($mode)` | Raw rows with a custom PDO fetch mode                                                  |

You can enable reverse pagination using the third argument, or the `reverse()` method. It does not change your original ORDER BY — it only flips how pages are calculated, so page 1 returns the last items instead of the first ones.

```php
// Messages sorted oldest → newest
$messages = Query::raw()->table('messages')
    ->where('room_id', 15)
    ->orderBy('id ASC')
    ->paginate(page: 1, pageSize: 3, reverse: true)
    ->objects();
// Returns the LAST 3 messages, not the first 3.
```

## Sql Expressions

`Azera\Db\Sql` embeds SQL expressions inside query builder calls: static factory methods produce typed value objects, serialized to safe SQL at query-compile time.

### Sql::raw() — literal SQL fragments

Inject unescaped SQL. Optional `$inlineValues` replaces named placeholders with escaped literals before the fragment is inserted.

```php
use Azera\Db\Sql;

// Increment counter in-place
Post::query()
    ->where('id', 5)
    ->update(['view_count' => Sql::raw('view_count + 1')]);

// Named placeholders inlined as escaped literals
Post::query()
    ->where('user_id', 1)
    ->update(['status' => Sql::raw(
        'CASE WHEN view_count > :popular THEN :pub ELSE :draft END',
        ['popular' => 100, 'pub' => 'published', 'draft' => 'draft']
    )]);
```

### Sql::param() / Sql::bind() — PDO bound parameters

`Sql::param(name)` emits `:name` as a placeholder; the value must arrive via `Query::bind()`.  
`Sql::bind(name, value)` emits `:name` **and** carries the value as a real PDO parameter — useful for binary data, full-text vectors, or JSON blobs that should not be inlined.

```php
// param() — value supplied separately via bind()
Query::raw()->table('articles')
    ->bind(['userId' => 42, 'ts' => time()])
    ->set([
        'updated_by' => Sql::param('userId'),
        'updated_at' => Sql::func('FROM_UNIXTIME', [Sql::param('ts')]),
    ])
    ->where('id', 1)
    ->update();

// bind() — value travels with the node
User::query()
    ->where('id', Sql::bind('uid', 42))
    ->update(['score' => Sql::raw('score + :inc', ['inc' => 5])]);
```

| Helper                           | SQL output        | Value delivery                                                |
| -------------------------------- | ----------------- | ------------------------------------------------------------- |
| `Sql::raw('x + :n', ['n' => 1])` | `x + 1` (literal) | Inlined (escaped)                                             |
| `Sql::param('n')`                | `:n`              | Placeholder only — value must be supplied via `Query::bind()` |
| `Sql::bind('n', 1)`              | `:n`              | Placeholder **and** value bubbled as PDO param                |

### Sql::column() — unquoted identifier reference

Refer to a column by name without quoting it as a string value.

```php
// Use column reference inside a function argument
Post::query()
    ->set('search', Sql::cast(
        Sql::func('to_tsvector', ['simple', Sql::column('title')]),
        'tsvector'
    ))
    ->where('id', 1)
    ->update();
// PostgreSQL: UPDATE posts SET search = to_tsvector('simple', title)::tsvector WHERE (id = 1)
```

### Sql::func() — SQL function calls

```php
// NOW(), COALESCE(), custom functions, …
Query::raw()->table('sessions')
    ->insert([
        'user_id'    => 42,
        'created_at' => Sql::func('NOW'),
        'expires_at' => Sql::func('DATE_ADD', [Sql::func('NOW'), Sql::raw('INTERVAL 30 MINUTE')]),
    ]);
```

### Sql::cast() — type casting

Driver-aware: PostgreSQL uses `expr::type`, MySQL/SQLite use `CAST(expr AS type)`.

```php
Query::raw()->table('stats')
    ->columns([Sql::cast(Sql::column('score'), 'DECIMAL(10,2)')->as('score_decimal')])
    ->select();
```

### Sql::concat() — string concatenation

Driver-aware: MySQL uses `CONCAT()`, PostgreSQL/SQLite use `||`.

```php
User::query()
    ->set('display_name', Sql::concat(Sql::column('first_name'), ' ', Sql::column('last_name')))
    ->where('id', 1)
    ->update();
```

### Sql::json() — JSON values

Serializes a PHP array/value to a JSON string literal.

```php
User::query()
    ->set('preferences', Sql::json(['theme' => 'dark', 'lang' => 'en']))
    ->where('id', 1)
    ->update();
```

### Sql::pgArray() — PostgreSQL array literals

```php
Query::raw()->table('posts')
    ->insert([
        'title' => 'PHP Tutorial',
        'tags'  => Sql::pgArray(['php', 'programming', 'web']),
    ]);
// INSERT INTO posts (title, tags) VALUES ('PHP Tutorial', '{"php","programming","web"}')
```

### Sql::csList() — comma-separated list (IN clauses)

```php
User::query()
    ->where('id IN (' . Sql::csList([1, 2, 3]) . ')')
    ->select();
```

### Sql::expr() — composite expressions

Concatenates parts with spaces. Plain strings are treated as raw SQL tokens; wrap values in `Sql::value()` to escape them.

```php
$expr = Sql::expr('COALESCE(', Sql::column('score'), ',', Sql::value(0), ')');
User::query()->columns([$expr->as('score')])->select();
```

### Sql::case() — CASE expressions

```php
$status = Sql::case()
    ->when(Sql::raw('score >= 90'), 'excellent')
    ->when(Sql::raw('score >= 70'), 'good')
    ->else('average')
    ->end();

User::query()
    ->columns(['id', 'username', $status->as('rating')])
    ->select();
```

### Sql::subQuery() — scalar subquery in column list

```php
$lastLogin = Sql::subQuery(
    Query::raw()->table('logins')
        ->columns('MAX(created_at)')
        ->where('user_id = users.id')
);

Query::raw()->table('users')
    ->columns(['id', 'username', $lastLogin->as('last_login')])
    ->select();
```

### ->as() — alias any Sql node

Any `Sql` node can be aliased with `->as('alias')`, useful in column lists:

```php
->columns([
    Sql::func('COUNT', ['*'])->as('total'),
    Sql::raw('SUM(amount)')->as('revenue'),
])
```

## Returning SQL Without Executing

```php
$sql = User::query()
    ->where('status', 'active')
    ->returnSql()
    ->select();
```

## Transactions

```php
$db = AppContext::instance()->dbManager()->get('write');

$db->begin();
try {
    User::query()->insert(['username' => 'alice', 'email' => 'alice@example.com']);
    User::query()->where('id', 1)->update(['status' => 'active']);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
```

## Table Resolvers

The query builder uses a **`TableResolver`** strategy to decide what a table name
means. The resolver turns a logical name (e.g. `User`, `users`, `App\Models\Order`)
into a concrete source descriptor: table name, schema, connection roles, model class
(for hydration), and primary key fields (for UPSERT).

### Built-in resolvers

| Resolver          | Used by                   | Behavior                                                                                                                               |
| ----------------- | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `LiteralResolver` | `Query::raw()`            | Treats the name as a literal table. Supports `schema.table` notation. No hydration.                                                    |
| `ModelResolver`   | `Query::new()` (fallback) | Resolves model class names via `source()`, `schema()`, `readRole()`, `writeRole()`, `idFields()`. Throws on unknown names (typo-safe). |
| `MappingResolver` | Custom                    | Resolves logical names from a `ModelMapping` configuration. No hydration, but supports per-model `connection`/`read`/`write` roles.    |
| `ChainResolver`   | AppContext default        | Tries each resolver in order; throws if none match (strict).                                                                           |

### Query entry points

```php
// Literal tables — no resolution, fast arrays
$rows = Query::raw()->table('users')->where('active', 1)->select();

// Model classes — resolves table, connection, enables hydration
$users = User::query()->where('status', 'active')->select();

// Custom resolver — low-level escape hatch
$query = Query::new()->using(new MappingResolver($mapping))->table('User');
```

### Registering a default resolver

Register a `ChainResolver` in `AppContext` at bootstrap so `Query::new()` can resolve
both model classes and mapping entries:

```php
use Azera\AppContext;
use Azera\Db\Resolver\ChainResolver;
use Azera\Db\Resolver\ModelResolver;
use Azera\Db\Resolver\MappingResolver;
use Azera\Db\Resolver\TableResolver;
use Azera\Db\ModelMapping;

$mapping = ModelMapping::fromArray([
    'User'    => 'users',
    'Order'   => ['source' => 'orders', 'schema' => 'public', 'connection' => 'analytics'],
]);

AppContext::instance()->set(TableResolver::class, new ChainResolver(
    new ModelResolver(),
    new MappingResolver($mapping),
));
```

Once registered, `Query::new()` uses the chain automatically — no per-call mapping needed:

```php
// Resolves 'User' via ModelResolver (if it's a class) or MappingResolver (if in mapping)
$results = Query::new()->table('User')->where('status', 'active')->select();
```

### Connection roles in ModelMapping

Each mapping entry can specify a `connection` key (sets both read+write) or
individual `read`/`write` overrides:

```php
$mapping = ModelMapping::fromArray([
    // Single connection for both read and write
    'Order' => ['source' => 'orders', 'connection' => 'analytics'],

    // Separate read and write connections
    'User' => ['source' => 'users', 'read' => 'replica', 'write' => 'primary'],
]);
```

### Typo detection

The strict `ChainResolver` and `ModelResolver` **throw** on unknown names.
`Query::new()->table('Userr')` throws `ResolveException` — a typo is never silently
treated as a literal table. To use literal table names, use `Query::raw()` explicitly.

## See Also

- [Models & ORM](04-MODELS-ORM.md)
- [Cookbook](11-COOKBOOK.md)
- [API Reference](api/README.md)
