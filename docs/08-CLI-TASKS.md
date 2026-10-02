# CLI Tasks

**Command-line tools** – tasks are auto-discovered from PSR-4 namespaces, receive parsed options, and produce color-highlighted output.

Azera provides `Azera\Cli\Console` (dispatcher + help system) and `Azera\Cli\Task` (base class for every task).

---

## Entry Point

Create `console.php` once at the project root:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Azera\Cli\Console;

$console = new Console();
// App\Tasks is included automatically; add other namespaces as needed:
// $console->addNamespace('App\\Admin\\Tasks');
$console->process($argv[1] ?? null, $argv[2] ?? null, array_slice($argv, 3));
```

Call signature on the command line:

```
php console.php <task> [<action>] [<arg1> <arg2> …] [--option] [--key=value]
```

`Console` separates positional arguments from options automatically before calling the action method. Options are available inside the task via `$this->options` or `$this->option()`.

---

## Task Discovery

`Console` scans registered namespaces for `*Task.php` files and registers
each `Task` subclass under a lowercase name (`DatabaseTask` → `database`).

```php
// App\Tasks is included automatically
// Register additional PSR-4 namespaces (any *Task.php inside will be found)
$console->addNamespace('App\\Extra\\Tasks');

// Register a raw filesystem directory (optionally set up a simple autoloader)
$console->addTaskPath('/path/to/extra/tasks', registerAutoload: true);
```

The built-in `Azera\Cli\Tasks` namespace (containing `ModelSyncTask`) is included by default. Discovery resolves paths from `composer.json` PSR-4 entries.

If you want to remove these built-in/system tasks, you can clear the tasks before processing. Call `clearTasks()` on the `Console` instance to unregister all built-in/system tasks:

```php
// Clear built-in/system tasks
$console->clearTasks();

// Then register your namespaces and process as usual
// $console->addNamespace('App\\Cli\\Tasks');
$console->process($argv[1] ?? null, $argv[2] ?? null, array_slice($argv, 3));
```

### Naming Rules

| Input                         | Resolved to              |
| ----------------------------- | ------------------------ |
| Task argument `database`      | class `DatabaseTask`     |
| Action argument `migrate`     | method `migrateAction()` |
| Action `run-all` or `run_all` | method `runAllAction()`  |

---

## Writing a Task

Extend `Task` and add public `*Action` methods. Positional arguments map to method parameters by position; options are available via `$this->options` / `$this->option()`.

```php
<?php
namespace App\Tasks;

use Azera\Cli\Task;

/**
 * Database maintenance utilities.
 *
 * Usage:
 *   php console.php database migrate [<target>] [--direction=<up|down>]
 *   php console.php database seed    [--truncate]
 *
 * Examples:
 *   php console.php database migrate              # migrate up to latest
 *   php console.php database migrate v3 --direction=down
 *   php console.php database seed --truncate
 */
class DatabaseTask extends Task
{
    /**
     * Run database migrations.
     */
    public function migrateAction(string $target = 'latest'): void
    {
        $direction = $this->option('direction', 'up');
        $this->info("Migrating {$direction} to {$target}…");
        // … migration logic …
        $this->success("Migration complete.");
    }

    /**
     * Seed the database with initial data.
     */
    public function seedAction(): void
    {
        if ($this->option('truncate')) {
            $this->warn("Truncating tables before seeding…");
        }
        // … seed logic …
        $this->success("Seeding complete.");
    }
}
```

---

## Single-Action vs Multi-Action Tasks

A task is either **single-action** (exactly one public `*Action` method) or **multi-action** (two or more). The Console detects this automatically from the class — there is no global "default action" to configure, and no special method name is required.

### Single-Action Tasks

For a single-action task, the bare task name invokes its only action. Any non-flag token that isn't the action name is treated as the first positional parameter:

```php
class EchoTask extends Task
{
    public function runAction(string $message = 'Hello!'): void
    {
        echo $message . PHP_EOL;
    }
}
```

```bash
php console.php echo                # calls runAction() with default
php console.php echo "Hi there"     # calls runAction("Hi there")
php console.php echo run "Hi"      # calls runAction("Hi") explicitly
```

In this case, `"Hi there"` is treated as the first positional parameter to `runAction()`, not as an action name — whichever method is the task's only action is the one that runs.

#### Help display

A single-action task's action name is shown in help **unless the action is `run`**. By convention, `run` means "execute the task itself" — the name adds nothing the task name doesn't already convey, so it is suppressed. Any other verb (`compile`, `diff`, `list`, …) describes what the task does and is shown.

A `run`-action task shows no `Actions:` section and a bare usage line:

```
Task: echo

Echo a message.

Usage:
  azera echo [args...]
```

A single-action task with a descriptive verb shows the action and a `task:action` usage line:

```
Task: docs

Operate the documentation subsystem.

Actions:
  docs:compile    Compiles documentation for a specified project.

Usage:
  azera docs:compile [args...]
```

### Multi-Action Tasks

For a multi-action task, you must name the action explicitly. The bare task name shows task help, and an unrecognized action name errors out and shows the help page instead of silently falling back — preventing typos from being swallowed.

```php
class DatabaseTask extends Task
{
    public function migrateAction(): void { /* … */ }
    public function seedAction(): void { /* … */ }
}
```

```bash
php console.php database            # shows task help (no implicit default)
php console.php database migrate    # calls migrateAction()
php console.php database typo       # error + shows task help (does NOT fall back)
```

Help output lists each action as `task:action`:

```
Actions:
  database:migrate    Run database migrations.
  database:seed       Seed the database with initial data.
```

---

## Reading Options

Options (`--flag`, `--key=value`, `--no-flag`) are parsed from the command line **before** calling the action method and are available as an associative array.

```php
// Option --apply        → $this->options['apply']  = true
// Option --no-apply     → $this->options['apply']  = false
// Option --database=read → $this->options['database'] = 'read'
// Option --count 5      → $this->options['count']   = 5   (with coercion on)

// Direct access
$dryRun = !isset($this->options['apply']);
$role   = $this->options['database'] ?? 'read';

// Helper with default
$role = $this->option('database', 'read');
$dryRun = !$this->option('apply', false);
```

---

## Middleware and AOP Interceptors

Instead of lifecycle hooks, tasks use the same **middleware** model as controllers, plus optional **AOP interceptors** for cross-cutting behavior. `Console` composes these into a pipeline around every action invocation:

```
[global] → [task] → [action] → [interceptors] → action method
```

Middleware and interceptors are declared as protected properties on the task class and run automatically — no action-method changes are needed.

### Middleware

Task-wide middleware runs for **every** action of the task; action-scoped middleware runs only for a specific method.

```php
<?php
namespace App\Tasks;

use Azera\Cli\Task;

class ImportTask extends Task
{
    // Runs for every action of this task.
    protected array $middlewares = [
        AuthMiddleware::class,
        [RoleMiddleware::class, ['admin']],
    ];

    // Runs only for the importAction method.
    protected array $actionMiddlewares = [
        'importAction' => [LogStartMiddleware::class],
    ];

    public function importAction(): void
    {
        // ...
    }
}
```

Middleware implement `Azera\Core\MiddlewareInterface` — the same interface used by HTTP controllers. The `process()` method receives the `AppContext` and a `$next` callable; call `$next()` to continue the pipeline. The CLI ignores the `?Response` return value.

```php
class LogStartMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $context->logger()->info('starting action');
        return $next();
    }
}
```

### Global middleware

Global middleware runs for every task action across all tasks. Register it on the `Console` instance:

```php
$console->addMiddleware(GlobalLogMiddleware::class);
```

### AOP interceptors

Tasks can also attach `Azera\Aop\InterceptorInterface` interceptors, composed as a plain closure chain (no proxy class generation / no eval). They wrap the action method **inside** the middleware layers:

```php
protected array $interceptors = [
    \Azera\Aop\Interceptor\LogInterceptor::class,
    [\Azera\Aop\Interceptor\RetryInterceptor::class, [3]],
];
```

### Example: log action start/end globally

A middleware receives the `AppContext` and a `$next` callable — it runs code **before** `$next()`, delegates, then runs code **after** it returns. Every task extending this base task logs around its actions:

```php
<?php
namespace App\Tasks;

use Azera\AppContext;
use Azera\Cli\Task;
use Azera\Core\MiddlewareInterface;
use Azera\Http\Response;

class LogActionMiddleware implements MiddlewareInterface
{
    public function process(AppContext $context, callable $next): ?Response
    {
        $context->logger()->info('action starting');

        $result = $next();

        $context->logger()->info('action finished');

        return $result;
    }
}

abstract class BaseTask extends Task
{
    protected array $middlewares = [LogActionMiddleware::class];
}
```

Any task that extends `BaseTask` runs `LogActionMiddleware` before and after every action, with no changes to the action methods themselves.

---

## Output Helpers

All output methods are available inside a task via `$this->…`. They delegate to the `Console` instance, which handles ANSI color automatically (enabled when the terminal supports it, disabled when piped).

| Method                                | Output style                      |
| ------------------------------------- | --------------------------------- |
| `$this->write($text)`                 | write text                        |
| `$this->writeln($text)`               | write line                        |
| `$this->stderr($text)`                | write to stderr                   |
| `$this->stderrln($text)`              | write line to stderr              |
| `$this->line($text)`                  | plain white                       |
| `$this->info($text)`                  | bright cyan                       |
| `$this->success($text)`               | bright green                      |
| `$this->warn($text)`                  | bright yellow                     |
| `$this->error($text)`                 | white on red                      |
| `$this->muted($text)`                 | gray / dim                        |
| `$this->style($text, 'bold', 'cyan')` | arbitrary named or rgb hex styles |

### Available Style Names

`bold`, `dim`, `red`, `green`, `yellow`, `blue`, `magenta`, `cyan`, `white`, `gray`,
`bred`, `bgreen`, `byellow`, `bcyan`, `bmagenta`, `bwhite`, `bg-red`, `bg-green`, `bg-yellow`, `bg-blue`, `bg-magenta`, `bg-cyan`, `bg-white`

### RGB Color Styles

`Console::color()` and `Console::style()` accept RGB values or hex codes:

```php
$console->style('Hex', '#ff00ff');                  // hex foreground
$console->style('Hex BG', 'bg #00ff00');            // hex background
$console->style('Custom RGB', $console->color(255, 0, 128));         // foreground RGB
$console->style('Custom BG', $console->color(0, 128, 255, true));    // background RGB
```

You can combine named styles and RGB/hex styles (named styles act as fallback for unsupported terminals):

```php
$console->style('Magenta text', 'bold', 'bmagenta', '#ff60ff');
```

When color support is disabled, the text is returned unchanged.

```php
public function importAction(string $file = ''): void
{
    $this->info("Importing {$file}…");

    foreach ($rows as $i => $row) {
        if ($row['error']) {
            $this->error("Row {$i}: " . $row['error']);
        } else {
            $this->muted("Row {$i}: ok");
        }
    }

    $this->success("Imported " . count($rows) . " rows.");
}
```

---

## Help System

The built-in help system parses the **PHPDoc comments** on the task class and its action methods. To show the help page for a task, run:

```
php console.php help                 # overview: all tasks + actions + descriptions
php console.php help <task>          # detail page: description, actions, usage, examples
```

The detail page parses these doc-comment sections:

```
Usage:    – syntax-highlighted with task/action/placeholders/options
Options:  – syntax-highlighted list of supported options
Examples: – syntax-highlighted examples for common use cases
```

### Example doc-comment

```php
/**
 * Import CSV data into the database.
 *
 * Usage:
 *   php console.php import run <file> [--truncate] [--batch=<size>]
 *
 * Options:
 *   --truncate      Empty the target table before importing
 *   --batch=<size>  Number of rows per insert batch (default: 100)
 *
 * Examples:
 *   php console.php import run data.csv                    # dry-run
 *   php console.php import run data.csv --truncate         # clear first
 *   php console.php import run data.csv --batch=500        # large batches
 */
class ImportTask extends Task { … }
```

---

## Console Configuration

```php
$console = new Console('myscript.php');  // custom script name shown in help

// Namespace / path registration
$console->addNamespace('App\\Admin\\Tasks');
$console->addTaskPath('/extra/tasks', registerAutoload: true);

// Colors
$console->enableColors(true);   // force on
$console->enableColors(false);  // force off (e.g. for CI)
$console->hasColors();          // bool

// Color a string directly (useful for custom Console subclasses)
echo $console->style('Hello!', 'bold', 'bgreen', '#5aff5a');

// Parameter type coercion ("5" → 5, "true" → true, "null" → null)
$console->setCoerceParams(true);
$console->shouldCoerceParams();
```

---

## Example with Models

Tasks have full access to your application context:

```php
<?php
namespace App\Tasks;

use App\Models\User;
use Azera\Cli\Task;

/**
 * User management utilities.
 *
 * Usage:
 *   php console.php user cleanup [--days=<n>]
 *   php console.php user seed
 *
 * Examples:
 *   php console.php user cleanup --days=90
 *   php console.php user seed
 */
class UserTask extends Task
{
    /**
     * Remove users who haven't logged in recently.
     */
    public function cleanupAction(): void
    {
        $days   = $this->option('days', 30);
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        $deleted = User::query()
            ->where('last_login < :cutoff', ['cutoff' => $cutoff])
            ->delete();

        $this->success("Deleted {$deleted} inactive users (cutoff: {$cutoff}).");
    }

    /**
     * Seed the users table with initial data.
     */
    public function seedAction(): void
    {
        User::create(['username' => 'admin', 'email' => 'admin@example.com']);
        $this->success("Seeded users.");
    }
}
```

---

## Console Discovery API

`Console` exposes several **public** helper methods that tasks can call via `$this->console`. These are the same primitives used internally for task auto-discovery, and they are useful when writing tasks that need to locate model or other class files at runtime.

```php
// Read the PSR-4 map from composer.json (result is cached per-instance)
$map = $this->console->readComposerPsr4(); // ['App\\' => '/project/src', ...]

// Find the root directory that contains composer.json
$root = $this->console->findComposerRoot(); // '/project'

// Resolve a namespace to an absolute directory path
$dir = $this->console->resolvePsr4Path('App\\Models'); // '/project/src/Models'

// Recursively list all .php files in a directory
$files = $this->console->scanDirectory('/project/src/Models');          // *.php
$tasks = $this->console->scanDirectory('/project/src/Tasks', 'Task.php'); // *Task.php

// Parse the FQCN from a PHP source file
$class = $this->console->extractClassFromFile('/project/src/Models/User.php');
// => 'App\\Models\\User'

// Detect the namespace used by files in a directory
$ns = $this->console->detectNamespace('/project/src/Models');
// => 'App\\Models'
```

All of these work without any framework bootstrap — they only need `composer.json` to be present in an ancestor directory.

---

## See Also

- [src/Cli/Console.php](../src/Cli/Console.php)
- [src/Cli/Task.php](../src/Cli/Task.php)

---

## Built-in: Sync Task

Azera ships with a ready-made `ModelSyncTask` that keeps your PHP model files in sync with the live database schema (DB → PHP). It is registered under the `Azera\Cli\Tasks` namespace and is auto-discovered – **no extra setup required**.

### Commands

```bash
php console.php model-sync all   [<directory>]     [options]  # scan a directory of model files (auto-discovers App\Models if omitted)
php console.php model-sync model <file-or-class>  [options]  # sync a single model (file path, short name, or FQN)
php console.php model-sync make  <ClassName>  [<directory>] [options]  # scaffold a new model file (auto-discovers App\Models if omitted)
```

The `<file-or-class>` argument for `model-sync model` accepts three forms:

| Form                 | Example                            |
| -------------------- | ---------------------------------- |
| File path            | `src/Models/User.php`              |
| Short class name     | `User` (auto-discovered via PSR-4) |
| Fully-qualified name | `App\Models\User`                  |

When no `<directory>` is given, `model-sync all` and `model-sync make` automatically resolve the target directory in this order:

1. The `App\Models` namespace resolved via the PSR-4 entries in `composer.json`
2. Common relative paths: `app/Models`, `src/Models`, `App/Models` (relative to cwd)

### Options

| Flag                       | Description                                                                         |
| -------------------------- | ----------------------------------------------------------------------------------- |
| _(none)_                   | Dry-run: preview changes without writing files                                      |
| `--apply`                  | Write the updated model files to disk                                               |
| `--database=<role>`        | Database role to introspect (default: `read`)                                       |
| `--generate-accessors`     | Generate a camelized getter/setter for each new property                            |
| `--field-visibility=<vis>` | Property visibility: `public` (default), `protected`, or `private`                  |
| `--no-deprecate`           | Skip `@deprecated` tags on properties whose columns have been removed               |
| `--create-missing`         | (`model-sync all` only) Scaffold model files for tables that have no matching model |
| `--directory=<dir>`        | (`model-sync model` only) Directory hint for class-name resolution                  |
| `--namespace=<ns>`         | PHP namespace for scaffolded model files (required with `--create-missing`)         |

### Examples

```bash
# Auto-discover App\Models and preview changes (no args needed)
php console.php model-sync all

# Preview changes for all models in an explicit directory
php console.php model-sync all src/Models

# Apply changes
php console.php model-sync all src/Models --apply

# Apply with protected properties and accessor methods
php console.php model-sync all src/Models --apply --generate-accessors --field-visibility=protected

# Scaffold models for any DB tables not yet represented, then sync them
php console.php model-sync all src/Models --apply --create-missing --namespace=App\\Models

# Sync a single file (file path)
php console.php model-sync model src/Models/User.php --apply

# Sync by short class name – auto-discovered via PSR-4
php console.php model-sync model User --apply

# Sync by fully-qualified class name
php console.php model-sync model App\Models\User --apply

# Sync by class name with an explicit directory hint
php console.php model-sync model User --directory=src/Models --apply

# Scaffold a new model – auto-discover App\Models directory
php console.php model-sync make Order

# Scaffold a new model into an explicit directory
php console.php model-sync make Order src/Models --namespace=App\\Models --apply
```

### How it works

1. **ModelParser** tokenises the PHP source and extracts class properties + metadata.
2. **SchemaProvider** (MySQL / PostgreSQL / SQLite) introspects the live database. Supply `--database=<role>` to target a specific connection.
3. **ModelDiff** computes the difference:
   - Missing PHP property → `AddProperty` (visibility from `--field-visibility`)
   - Missing DB column → `RemoveProperty` (marks `@deprecated`, skipped with `--no-deprecate`)
   - Type mismatch → `UpdatePropertyType`
   - With `--generate-accessors`: an `AddAccessor` is paired with each added property
   - Properties and columns starting with `_` are always skipped
4. **CodeGenerator** applies the operations in-place using string manipulation.
5. **SyncRunner** orchestrates the pipeline and writes the result back to disk.
