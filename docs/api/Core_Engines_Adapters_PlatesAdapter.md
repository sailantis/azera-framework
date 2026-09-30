# Class: PlatesAdapter

**Full name:** [Azera\Core\Engines\Adapters\PlatesAdapter](../../src/Core/Engines/Adapters/PlatesAdapter.php)

Plates template engine adapter.

Wraps League/Plates so Azera applications can use `.plates.php` templates.
Requires `league/plates` to be installed:

```sh
composer require league/plates
```

Plates does not use a disk cache; compiled output is plain PHP that the
PHP runtime (and OPcache) handle directly.

Filters are mapped to Plates *template functions*, which are called inside
templates as `$this->filterName($value)`.

## Public methods

### addNamespace() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L38)</small>

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Also registers the namespace as a Plates folder so templates can use
`namespace::view` syntax.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**Return value**

- Type: `static`


---

### addFilter() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L59)</small>

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Plates does not distinguish between filters and functions; both are
registered as Plates *template functions* and called inside templates as
`$this->name($value, ...$args)`.  This method delegates to
`addFunction()`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | fn($value, ...$args): mixed |

**Return value**

- Type: `static`


---

### addFunction() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L73)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Registers a Plates template function, callable inside templates as
`$this->name($arg1, $arg2)`.

Plates does not distinguish between filters and functions at the API
level; `addFilter()` is an alias for this method.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`


---

### getDriver() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L87)</small>

`public function getDriver(): mixed`

Return the underlying engine/driver object for advanced configuration.

Returns the underlying `\League\Plates\Engine` instance for advanced
configuration (extensions, data, etc.).
Initialises Plates on first call if not already done.

**Return value**

- Type: `mixed`


---

### render() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L149)</small>

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

### renderPartial() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L159)</small>

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

### renderLayout() · <small>[🗎](../../src/Core/Engines/Adapters/PlatesAdapter.php#L173)</small>

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

[Back to the Index ⤴](README.md)
