# Class: HelpRendering

**Full name:** [Azera\Cli\Console\HelpRendering](../../src/Cli/Console/HelpRendering.php)

Help system: overview + per-task help, doc-comment parsing,
syntax-highlighted command/usage/options rendering, word wrap,
and tabular output.

## Public methods

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
