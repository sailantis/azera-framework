# Class: MigrateTask

**Full name:** [Azera\Cli\Tasks\MigrateTask](../../src/Cli/Tasks/MigrateTask.php)

Generate SQL to bring the database schema in line with PHP model definitions.

This is a one-directional tool: PHP → DB. It compares your model classes
against the live database and produces the ALTER/CREATE statements needed
to make the DB match the models.

No migration tracking table or versioned files — the diff is always
computed fresh from the current state.

Usage:
  migrate:diff                          Compare all models, output SQL
  migrate:diff --model=User             Compare a single model
  migrate:diff --apply                  Execute the SQL directly
  migrate:diff --file=output.sql        Write SQL to a file
  migrate:diff --dry-run                Show SQL without executing (default)

Options:
  --model=<name>     Compare a single model (by class name or file path)
  --apply            Execute the generated SQL against the database
  --dry-run          Show SQL without executing (this is the default)
  --file=<path>      Write the generated SQL to a file
  --database=<role>  Database role to use (default: "read")
  --confirm          Skip confirmation prompt when using --apply

Examples:
  migrate:diff
  migrate:diff --model=User
  migrate:diff --model=src/Models/User.php
  migrate:diff --apply
  migrate:diff --apply --confirm
  migrate:diff --file=database/migrations/add_avatar.sql

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/MigrateTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/MigrateTask.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Cli/Tasks/MigrateTask.php#L50)</small>

`public function __construct(): mixed`

**Return value**

- Type: `mixed`


---

### diffAction() · <small>[🗎](../../src/Cli/Tasks/MigrateTask.php#L59)</small>

`public function diffAction(): void`

Compare PHP model definitions against the database and generate SQL.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
