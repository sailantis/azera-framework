# Class: Console

**Full name:** [Azera\Cli\Console](../../src/Cli/Console.php)

Main Console class for registering and dispatching CLI tasks.

Tasks are PHP classes that extend the base Task class and define public methods
ending with "Action". These methods can be invoked as CLI commands.

The Console class supports automatic discovery of task classes in specified
namespaces and directories, as well as a built-in help system that extracts
descriptions from doc comments.

## Public Constants

- **SHRINK_MARKER** = `'↰'`
- **STYLE_ERROR** = `[
    'bg-red',
    'white',
    'bold'
]`
- **STYLE_WARN** = `[
    'byellow'
]`
- **STYLE_INFO** = `[
    'bcyan'
]`
- **STYLE_SUCCESS** = `[
    'bgreen'
]`
- **STYLE_MUTED** = `[
    'gray'
]`

## Public methods

### __construct() · <small>[🗎](../../src/Cli/Console.php#L90)</small>

`public function __construct(string|null $scriptName = null): mixed`

Console constructor.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$scriptName` | string\|null | `null` | Optional custom script name for help output. Defaults to the basename of argv[0]. |

**Return value**

- Type: `mixed`


---

### setGlobalTaskHelp() · <small>[🗎](../../src/Cli/Console.php#L110)</small>

`public function setGlobalTaskHelp(string|null $help): void`

Set global help text that is appended to every help per-task detail
output. Use the same plain-text format as docblock Options sections:

--flag              One-line description
  --key=<value>       Description aligned automatically

Pass null to clear previously set help.

To suppress this section for a specific task, set
`protected bool $showGlobalHelp = false` on that task class.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$help` | string\|null | - | The help text, or null to clear. |

**Return value**

- Type: `void`


---

### getGlobalTaskHelp() · <small>[🗎](../../src/Cli/Console.php#L118)</small>

`public function getGlobalTaskHelp(): string|null`

Return the currently registered global task help text, or null if none is set.

**Return value**

- Type: `string`|`null`


---

### singleActionMethod() · <small>[🗎](../../src/Cli/Console.php#L153)</small>

`public function singleActionMethod(string $class): string|null`

Return the single action method name for a task with exactly one
public dispatchable action, or null when the task has 0 or 2+ actions.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - | Fully-qualified task class name. |

**Return value**

- Type: `string`|`null`
- Description: The method name (e.g. "runAction") or null.


---

### clearTasks() · <small>[🗎](../../src/Cli/Console.php#L160)</small>

`public function clearTasks(): void`

Remove all registered tasks. Useful if you don't want to expose system tasks.

**Return value**

- Type: `void`


---

### addMiddleware() · <small>[🗎](../../src/Cli/Console.php#L173)</small>

`public function addMiddleware(mixed $middleware): void`

Register a global middleware that runs for every task action.

Accepts a [`MiddlewareInterface`](Core_MiddlewareInterface.md) instance, a class
string, an array [class, args], or a closure factory.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$middleware` | mixed | - |  |

**Return value**

- Type: `void`


---

### shouldCoerceParams() · <small>[🗎](../../src/Cli/Console.php#L191)</small>

`public function shouldCoerceParams(): bool`

Check whether automatic parameter type coercion is enabled.

When enabled, string arguments that look like integers, floats, booleans,
or NULL are converted to the corresponding PHP scalar before being passed
to the action method.

**Return value**

- Type: `bool`
- Description: True if parameter coercion is enabled.


---

### setCoerceParams() · <small>[🗎](../../src/Cli/Console.php#L201)</small>

`public function setCoerceParams(bool $coerceParams): void`

Enable or disable automatic parameter type coercion.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$coerceParams` | bool | - | True to enable coercion, false to pass all arguments as strings. |

**Return value**

- Type: `void`


---

### process() · <small>[🗎](../../src/Cli/Console.php#L229)</small>

`public function process(array $argv): void`

Primary entry point. Accepts the full argv slice (without the script
name) and handles global flags before positional dispatch.

Recognised forms:
  azera                              → overview help
  azera --help | -h                  → overview help
  azera help                         → overview help
  azera help <task>                  → help for <task>
  azera <task>                       → default action
  azera <task> --help | -h           → help for <task>
  azera <task> <action>              → dispatch action
  azera <task> <action> [args...]    → dispatch action with options
  azera [args...] <task> [args...]   → global opts are peeled off
                                             before positional parsing

Tokens that look like flags (start with '-' or '--') and appear before
the first non-flag token are stripped from the positional stream and
stored in $globalOptions; tasks can read them via
$this->options('global.<name>') or via the merged $task->options array.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$argv` | array | - | Raw argv slice (argv[1..] from PHP's $argv). |

**Return value**

- Type: `void`


---

### coerceParam() · <small>[🗎](../../src/Cli/Console.php#L589)</small>

`public function coerceParam(string $param): string|int|float|bool|null`

Coerce a string parameter to int, float, bool, or null if it looks like one of those.

Otherwise return the original string. Empty string is returned as-is.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$param` | string | - | The parameter string to coerce. |

**Return value**

- Type: `string`|`int`|`float`|`bool`|`null`
- Description: The coerced value, or original string if no coercion applied.


---

### enableColors() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L185)</small>

`public function enableColors(bool $colors): void`

Enable or disable ANSI color output explicitly.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$colors` | bool | - |  |

**Return value**

- Type: `void`


---

### hasColors() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L191)</small>

`public function hasColors(): bool`

Check whether ANSI color output is enabled.

**Return value**

- Type: `bool`


---

### color() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L209)</small>

`public function color(string|int $r, int|null $g = null, int|null $b = null, bool $background = false): string`

Generate an ANSI escape code for a custom RGB color.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$r` | string\|int | - | Either a hex color code (e.g. "#ff0000" or "bg:#00ff00" or "bg #00ff00") or the red component (0-255). |
| `$g` | int\|null | `null` | The green component (0-255), required if $r is not a hex code. |
| `$b` | int\|null | `null` | The blue component (0-255), required if $r is not a hex code. |
| `$background` | bool | `false` | Whether this color is for background (true) or foreground (false). |

**Return value**

- Type: `string`
- Description: The ANSI escape code for the specified color, or an empty string if colors are disabled or input is invalid.


---

### style() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L261)</small>

`public function style(string $text, string ...$styles): string`

Apply one or more named ANSI styles or a custom color to a string.

Style names: bold, dim, red, green, yellow, blue, magenta, cyan, white, gray, bred, bgreen, byellow, bcyan, bg-red, bg-green, bg-yellow, bg-blue, bg-magenta, bg-cyan, bg-white
Custom colors can be specified via hex code (e.g. "#ff0000" or "bg:#00ff00" or "bg #00ff00").

When color support is disabled, the text is returned unchanged.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |
| `$styles` | string | - |  |

**Return value**

- Type: `string`


---

### write() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L285)</small>

`public function write(string $text = ''): void`

Write text to stdout.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### writeln() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L291)</small>

`public function writeln(string $text = ''): void`

Write a line to stdout (newline appended).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### stderr() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L297)</small>

`public function stderr(string $text): void`

Write text to stderr.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### stderrln() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L303)</small>

`public function stderrln(string $text): void`

Write a line to stderr (newline appended).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### stdout() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L309)</small>

`public function stdout(string $text): void`

Write text to stdout.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### stdoutln() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L315)</small>

`public function stdoutln(string $text): void`

Write a line to stdout (newline appended).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### line() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L325)</small>

`public function line(string $text): void`

Plain informational line.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### info() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L333)</small>

`public function info(string $text): void`

Write an informational message (cyan). Newline is appended automatically.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### success() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L341)</small>

`public function success(string $text): void`

Write a success message (green). Newline is appended automatically.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### warn() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L349)</small>

`public function warn(string $text): void`

Write a warning message (yellow). Newline is appended automatically.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### error() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L357)</small>

`public function error(string $text): void`

Write an error message (white on red) to STDERR. Newline is appended automatically.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### muted() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L365)</small>

`public function muted(string $text): void`

Write a muted / dimmed message. Newline is appended automatically.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### printTable() · <small>[🗎](../../src/Cli/Console/OutputRendering.php#L375)</small>

`public function printTable(array $headers, array $rows): void`

Print a simple table with unicode-aware column width calculation.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$headers` | array | - | The table headers as a numeric array. |
| `$rows` | array | - | The table rows as numeric arrays. |

**Return value**

- Type: `void`


---

### addNamespace() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L19)</small>

`public function addNamespace(string $ns): void`

Register a namespace to search for tasks. Namespaces are resolved to directories via PSR-4 rules.

By default, "App\\Tasks" is registered. The framework's own built-in tasks are pre-registered
directly without any filesystem scan.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ns` | string | - |  |

**Return value**

- Type: `void`


---

### addTaskPath() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L31)</small>

`public function addTaskPath(string $path, bool $registerAutoload = false): void`

Register a directory path to search for task classes. This is in addition to any namespaces registered via addNamespace().

You can set $registerAutoload to true to automatically register a simple PSR-4 autoloader for this path.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |
| `$registerAutoload` | bool | `false` |  |

**Return value**

- Type: `void`


---

### setComposerRoot() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L54)</small>

`public function setComposerRoot(string|null $dir): void`

Explicitly set the composer/project root directory used for PSR-4
resolution and task autodiscovery. When set, this takes precedence
over the walk-up heuristic in findComposerRoot().

Pass the project root directory (the folder containing composer.json)
so that task discovery scans the project's own autoload paths instead
of the framework's.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string\|null | - | Absolute path to the project root, or null to<br>fall back to automatic detection. |

**Return value**

- Type: `void`


---

### autodiscover() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L60)</small>

`public function autodiscover(): void`

Autodiscover tasks in all registered namespaces and paths

**Return value**

- Type: `void`


---

### readComposerPsr4() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L97)</small>

`public function readComposerPsr4(): array`

Return the full PSR-4 map from the nearest composer.json.

Result is cached for the lifetime of this Console instance.

**Return value**

- Type: `array`
- Description: namespace prefix => absolute directory


---

### findComposerRoot() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L129)</small>

`public function findComposerRoot(): string|null`

Walk up the directory tree from this file until composer.json is found.

Falls back to the current working directory.

**Return value**

- Type: `string`|`null`


---

### resolvePsr4Path() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L168)</small>

`public function resolvePsr4Path(string $namespace): string|null`

Resolve a PHP namespace to an absolute directory using the PSR-4 map.

Falls back to guessing a path relative to the current working directory.

Example: "App\\Models" => "/project/src/Models"

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$namespace` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### scanDirectory() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L201)</small>

`public function scanDirectory(string $dir, string $suffix = '.php'): array`

Recursively scan $dir and return sorted absolute paths to files whose
name ends with $suffix (default ".php").

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string | - |  |
| `$suffix` | string | `'.php'` |  |

**Return value**

- Type: `array`


---

### extractClassFromFile() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L224)</small>

`public function extractClassFromFile(string $file): string|null`

Extract the fully-qualified class name from a PHP source file by
parsing its namespace declaration and the file's base name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$file` | string | - |  |

**Return value**

- Type: `string`|`null`


---

### detectNamespace() · <small>[🗎](../../src/Cli/Console/TaskDiscovery.php#L241)</small>

`public function detectNamespace(string $dir): string`

Detect the PHP namespace declared in any .php file directly inside $dir.

Returns an empty string if none is found.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$dir` | string | - |  |

**Return value**

- Type: `string`


---

### helpOverview() · <small>[🗎](../../src/Cli/Console/HelpRendering.php#L15)</small>

`public function helpOverview(): void`

Built-in help task

**Return value**

- Type: `void`


---

### helpTask() · <small>[🗎](../../src/Cli/Console/HelpRendering.php#L119)</small>

`public function helpTask(string $task): void`

Built-in help task for a specific task

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$task` | string | - | The name of the task to display help for |

**Return value**

- Type: `void`


---

### terminalWidth() · <small>[🗎](../../src/Cli/Console/HelpRendering.php#L531)</small>

`public function terminalWidth(): int`

Return detected terminal width (columns). Falls back to 80.

**Return value**

- Type: `int`


---

### wrapText() · <small>[🗎](../../src/Cli/Console/HelpRendering.php#L915)</small>

`public function wrapText(string $text, int $width): array`

Word-wrap a text block into an array of lines for the given column width.

Lines are trimmed of trailing whitespace. Empty input returns an array with one empty string.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - | The text to wrap. |
| `$width` | int | - | The maximum column width for wrapping. |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
