# Class: OutputRendering

**Full name:** [Azera\Cli\Console\OutputRendering](../../src/Cli/Console/OutputRendering.php)

ANSI color/styling and convenience output methods.

## Public Constants

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

[Back to the Index ⤴](README.md)
