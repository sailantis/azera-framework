# Class: DbTask

**Full name:** [Azera\Cli\Tasks\DbTask](../../src/Cli/Tasks/DbTask.php)

Inspect the database schema and run raw SQL queries.

Usage:
  db:tables  [--database=<role>] [--schema=<name>] [--with-counts]
  db:table  <table>  [--database=<role>] [--schema=<name>]
  db:query   <sql>    [--database=<role>] [--force] [--file=<path>]

Options:
  --database=<role>   Database role to use (default: "default")
  --schema=<name>     Database schema to list tables from (PostgreSQL only)
  --with-counts       (tables) Also show approximate row counts
  --force             (query) Allow destructive queries (DROP/TRUNCATE/DELETE/ALTER)
  --file=<path>       (query) Read SQL from a file instead of an argument

Examples:
  db:tables
  db:tables --with-counts
  db:table users
  db:query "SELECT * FROM users LIMIT 5"
  db:query --file=migration.sql --force

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/DbTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/DbTask.php)</small>

## Public methods

### tablesAction() · <small>[🗎](../../src/Cli/Tasks/DbTask.php#L37)</small>

`public function tablesAction(): void`

List all tables in the database.

**Return value**

- Type: `void`


---

### tableAction() · <small>[🗎](../../src/Cli/Tasks/DbTask.php#L96)</small>

`public function tableAction(string $table = ''): void`

Show column details for a specific table (db:table).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$table` | string | `''` |  |

**Return value**

- Type: `void`


---

### queryAction() · <small>[🗎](../../src/Cli/Tasks/DbTask.php#L168)</small>

`public function queryAction(string $sql = ''): void`

Execute a raw SQL query and display results.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$sql` | string | `''` |  |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
