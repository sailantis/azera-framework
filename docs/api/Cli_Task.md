# Class: Task

**Full name:** [Azera\Cli\Task](../../src/Cli/Task.php)

Base class for all CLI task classes.

Extend this class to create a CLI task. Public methods ending in "Action"
are automatically discoverable by [`Console`](Cli_Console.md).

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Task.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Task.php)</small>

## Public methods

### write() · <small>[🗎](../../src/Cli/Task.php#L32)</small>

`public function write(string $text = ''): void`

Write text without a newline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### writeln() · <small>[🗎](../../src/Cli/Task.php#L38)</small>

`public function writeln(string $text = ''): void`

Write a line of text with a newline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### stderr() · <small>[🗎](../../src/Cli/Task.php#L44)</small>

`public function stderr(string $text = ''): void`

Write to STDERR without a newline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### stderrln() · <small>[🗎](../../src/Cli/Task.php#L50)</small>

`public function stderrln(string $text = ''): void`

Write to STDERR with a newline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### stdout() · <small>[🗎](../../src/Cli/Task.php#L56)</small>

`public function stdout(string $text = ''): void`

Write to STDOUT without a newline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### stdoutln() · <small>[🗎](../../src/Cli/Task.php#L62)</small>

`public function stdoutln(string $text = ''): void`

Write to STDOUT with a newline.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | `''` |  |

**Return value**

- Type: `void`


---

### line() · <small>[🗎](../../src/Cli/Task.php#L68)</small>

`public function line(string $text): void`

Plain message with no styling. Newline is appended.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### info() · <small>[🗎](../../src/Cli/Task.php#L74)</small>

`public function info(string $text): void`

Informational message (cyan). Newline is appended.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### success() · <small>[🗎](../../src/Cli/Task.php#L80)</small>

`public function success(string $text): void`

Success message (green). Newline is appended.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### warn() · <small>[🗎](../../src/Cli/Task.php#L86)</small>

`public function warn(string $text): void`

Warning message (yellow). Newline is appended.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### error() · <small>[🗎](../../src/Cli/Task.php#L92)</small>

`public function error(string $text): void`

Error message (white on red) to STDERR. Newline is appended.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### muted() · <small>[🗎](../../src/Cli/Task.php#L98)</small>

`public function muted(string $text): void`

Muted / dimmed text (gray). Newline is appended.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$text` | string | - |  |

**Return value**

- Type: `void`


---

### option() · <small>[🗎](../../src/Cli/Task.php#L124)</small>

`public function option(string $key, mixed $default = null): mixed`

Retrieve a parsed option value by key, with an optional default.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | The option name (without leading dashes). |
| `$default` | mixed | `null` | The default value to return if the option is not set. |

**Return value**

- Type: `mixed`
- Description: The option value or the default if not set.


---

### context() · <small>[🗎](../../src/Cli/Task.php#L133)</small>

`public function context(): Azera\AppContext`

Get the current AppContext instance. Useful for accessing services.

**Return value**

- Type: [AppContext](AppContext.md)


---

### getMiddlewares() · <small>[🗎](../../src/Cli/Task.php#L185)</small>

`public function getMiddlewares(): array`

Get the middleware for the task. Used by the Console to build the
middleware pipeline when dispatching an action.

**Return value**

- Type: `array`


---

### getActionMiddlewares() · <small>[🗎](../../src/Cli/Task.php#L196)</small>

`public function getActionMiddlewares(string $action): array`

Get the middleware for a specific action. Used by the Console to build
the middleware pipeline when dispatching an action.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$action` | string | - | The resolved PHP method name (e.g. "runAction"). |

**Return value**

- Type: `array`


---

### getInterceptors() · <small>[🗎](../../src/Cli/Task.php#L206)</small>

`public function getInterceptors(): array`

Get the AOP interceptors for this task. Used by the Console to wrap the
action method in an interceptor chain.

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
