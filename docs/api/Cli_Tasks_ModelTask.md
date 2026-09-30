# Class: ModelTask

**Full name:** [Azera\Cli\Tasks\ModelTask](../../src/Cli/Tasks/ModelTask.php)

CLI task for synchronising PHP model properties from the database schema (DB→PHP)
and for scaffolding new model files from database tables.

Usage:
  model:sync-all [<models-dir>] [--apply] [--database=<role>]
                                [--generate-accessors] [--no-deprecate]
                                [--field-visibility=<public|protected|private>]
                                [--create-missing] [--namespace=<ns>]
  model:sync     <file-or-class> [--apply] [--database=<role>]
                                [--generate-accessors]
                                [--field-visibility=<public|protected|private>]
                                [--no-deprecate] [--directory=<dir>]
  model:new       <ClassName>  [<directory>] [--apply]
                                [--database=<role>] [--namespace=<ns>]
                                [--generate-accessors] [--no-deprecate]
                                [--field-visibility=<public|protected|private>]

  model:list      [<models-dir>] [--database=<role>] [--missing]

The <file-or-class> argument for `model:sync` accepts:

  - A file path:               src/Models/User.php
↰
  - A short class name:        User   (discovered via PSR-4 / --directory)
↰
  - A fully-qualified name:    App\Models\User

By default the task only reports changes.
Pass --apply to write the updated model files to disk.

Options:
  --apply                     Apply changes to files instead of just
                              reporting them
  --create-missing            Create new model files for tables that don't
                              have a corresponding model yet
  --database=<role>           Database role to use for schema introspection
                              (default: "read")
  --directory=<dir>           Hint directory for class-name resolution in
                              `model:sync` (optional)
  --field-visibility=<vis>    Visibility for generated properties (default:
                              "public")
  --generate-accessors        Also generate getter/setter methods for each
                              property
  --namespace=<ns>            Namespace to use when creating new model files
                              (required if --create-missing is used)
  --missing                   (model:list) Also list database tables that
                              have no corresponding model file yet
  --no-deprecate              Don't add @deprecated tags to removed
                              properties

Examples:
  model:sync-all                                          # auto-discover App\Models
  model:sync-all  src/Models                              # dry-run
  model:sync-all  src/Models --apply                      # apply
  model:sync-all  src/Models --apply --generate-accessors # with accessors
  model:sync-all  src/Models --apply --field-visibility=protected
  model:sync-all  src/Models --apply --no-deprecate
  model:sync-all  src/Models --apply --create-missing --namespace=App\\Models
  model:sync     src/Models/User.php --apply               # file path
  model:sync     User --apply                              # short class name (PSR-4)
  model:sync     App\\Models\\User --apply                 # fully-qualified name
  model:sync     User --directory=src/Models --apply       # with directory hint
  model:new      User                                      # auto-discover App\Models dir
  model:new      User src/Models --namespace=App\\Models --apply
  model:list                                               # list all models + tables
  model:list     src/Models --missing                      # also show uncovered tables

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/ModelTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/ModelTask.php)</small>

## Public methods

### listAction() · <small>[🗎](../../src/Cli/Tasks/ModelTask.php#L91)</small>

`public function listAction(string $dir = ''): void`

List all model files in a directory together with their mapped database
table. With --missing, also list database tables that have no
corresponding model file yet.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string | `''` | Directory to scan (optional – defaults to App\Models via PSR-4) |

**Return value**

- Type: `void`


---

### syncAllAction() · <small>[🗎](../../src/Cli/Tasks/ModelTask.php#L190)</small>

`public function syncAllAction(string $dir = ''): void`

Scan a directory recursively, find all PHP files that extend Model,
and sync each one against the database.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string | `''` | Directory to scan (optional – defaults to App\\Models via PSR-4) |

**Return value**

- Type: `void`


---

### syncAction() · <small>[🗎](../../src/Cli/Tasks/ModelTask.php#L295)</small>

`public function syncAction(string $file = ''): void`

Sync a single model against the database.

The argument may be a file path, a short class name, or a fully-qualified
class name. Short and qualified class names are resolved to file paths
via the PSR-4 autoloading map. Use --directory=<dir> to narrow the search
when two classes share the same short name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$file` | string | `''` | File path, short class name, or fully-qualified class name (required) |

**Return value**

- Type: `void`


---

### newAction() · <small>[🗎](../../src/Cli/Tasks/ModelTask.php#L341)</small>

`public function newAction(string $className = '', string $dir = ''): void`

Scaffold a new model class from a database table and immediately sync its properties.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$className` | string | `''` | Short class name without namespace (e.g. User) |
| `$dir` | string | `''` | Target directory for the new file (optional – defaults to App\\Models via PSR-4) |

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
