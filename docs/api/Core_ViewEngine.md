# Class: ViewEngine

**Full name:** [Azera\Core\ViewEngine](../../src/Core/ViewEngine.php)

Abstract base for all view engine implementations.

Holds shared configuration state (path, layout, extension, namespaces,
global variables, render depth) and the path-resolution logic.
Concrete engines (NativeEngine, ClarityEngine, …) extend this class and
implement the three abstract rendering methods.

## Public methods

### __construct() · <small>[🗎](../../src/Core/ViewEngine.php#L29)</small>

`public function __construct(array $vars = []): mixed`

Create a new ViewEngine instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` | Initial variables available to all views. |

**Return value**

- Type: `mixed`


---

### setExtension() · <small>[🗎](../../src/Core/ViewEngine.php#L40)</small>

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**Return value**

- Type: `static`


---

### getExtension() · <small>[🗎](../../src/Core/ViewEngine.php#L54)</small>

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**Return value**

- Type: `string`
- Description: Extension including leading dot or empty string.


---

### addNamespace() · <small>[🗎](../../src/Core/ViewEngine.php#L68)</small>

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Views can be referenced using the syntax "namespace::view.name".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**Return value**

- Type: `static`


---

### getNamespaces() · <small>[🗎](../../src/Core/ViewEngine.php#L79)</small>

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**Return value**

- Type: `array`
- Description: Associative array of namespace => path mappings.


---

### setViewPath() · <small>[🗎](../../src/Core/ViewEngine.php#L90)</small>

`public function setViewPath(string $path): static`

Set the base path for resolving relative view names.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for views. |

**Return value**

- Type: `static`


---

### getViewPath() · <small>[🗎](../../src/Core/ViewEngine.php#L101)</small>

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**Return value**

- Type: `string`
- Description: Base directory for views.


---

### setLayout() · <small>[🗎](../../src/Core/ViewEngine.php#L115)</small>

`public function setLayout(string|null $layout): static`

Set the layout template name to be used when calling `render()`.

The layout will receive a `content` variable containing the
rendered view output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string\|null | - | Layout view name or null to disable. |

**Return value**

- Type: `static`


---

### getLayout() · <small>[🗎](../../src/Core/ViewEngine.php#L126)</small>

`public function getLayout(): string|null`

Get the currently configured layout view name.

**Return value**

- Type: `string`|`null`
- Description: Layout name or null when none set.


---

### setVar() · <small>[🗎](../../src/Core/ViewEngine.php#L138)</small>

`public function setVar(string $name, mixed $value): static`

Set a single view variable.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Variable name available inside templates. |
| `$value` | mixed | - | Value assigned to the variable. |

**Return value**

- Type: `static`


---

### setVars() · <small>[🗎](../../src/Core/ViewEngine.php#L152)</small>

`public function setVars(array $vars): static`

Merge multiple variables into the view's variable set.

Later values override earlier ones for the same keys.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | - | Associative array of variables. |

**Return value**

- Type: `static`


---

### render() · <small>[🗎](../../src/Core/ViewEngine.php#L165)</small>

`public function render(string $view, array $vars = []): string`

Render a view (and optional layout) and return the result.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to render. |
| `$vars` | array | `[]` | Additional variables for this render call. |

**Return value**

- Type: `string`
- Description: Rendered content.


---

### renderPartial() · <small>[🗎](../../src/Core/ViewEngine.php#L174)</small>

`public function renderPartial(string $view, array $vars = []): string`

Render a partial view (without applying a layout) and return the output.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to resolve and render. |
| `$vars` | array | `[]` | Variables for this render call. |

**Return value**

- Type: `string`
- Description: Rendered HTML/output.


---

### renderLayout() · <small>[🗎](../../src/Core/ViewEngine.php#L186)</small>

`public function renderLayout(string $layout, string $content, array $vars = []): string`

Render a layout template wrapping provided content.

The layout receives the rendered view in the `content` variable.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string | - | Layout view name. |
| `$content` | string | - | Previously rendered content. |
| `$vars` | array | `[]` | Additional variables to pass to the layout. |

**Return value**

- Type: `string`
- Description: Rendered layout output.


---

### getRenderDepth() · <small>[🗎](../../src/Core/ViewEngine.php#L194)</small>

`public function getRenderDepth(): int`

Get current render nesting depth. Useful to detect top-level renders
(depth 0) when deciding whether to apply a layout.

**Return value**

- Type: `int`
- Description: Current render depth (0 = top-level).


---

### addFilter() · <small>[🗎](../../src/Core/ViewEngine.php#L267)</small>

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Filters transform a piped value and are invoked with pipe syntax,
e.g. `{{ value|name }}` or `{{ value|name(arg) }}`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | fn($value, ...$args): mixed |

**Return value**

- Type: `static`


---

### addFunction() · <small>[🗎](../../src/Core/ViewEngine.php#L282)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Functions are called directly in templates, e.g. `{{ name(arg) }}`.
This is distinct from filters, which transform a piped value.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`


---

### getDriver() · <small>[🗎](../../src/Core/ViewEngine.php#L297)</small>

`public function getDriver(): mixed`

Return the underlying engine/driver object for advanced configuration.

Returns the raw engine instance (e.g. `\Twig\Environment`,
`\League\Plates\Engine`, `\Illuminate\View\Factory`) for cases not
covered by the adapter API.  Returns `null` for engines without a
separate driver object (Clarity, Native).

**Return value**

- Type: `mixed`


---

### setCachePath() · <small>[🗎](../../src/Core/ViewEngine.php#L306)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/Core/ViewEngine.php#L314)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`


---

### flushCache() · <small>[🗎](../../src/Core/ViewEngine.php#L322)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`



---

[Back to the Index ⤴](README.md)
