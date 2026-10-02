# Class: TaskDiscovery

**Full name:** [Azera\Cli\Console\TaskDiscovery](../../src/Cli/Console/TaskDiscovery.php)

Task autodiscovery: PSR-4 resolution, composer.json walking, and
filesystem scanning for task classes.

## Public methods

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

[Back to the Index ⤴](README.md)
