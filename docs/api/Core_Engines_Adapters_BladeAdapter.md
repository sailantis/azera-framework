# Class: BladeAdapter

**Full name:** [Azera\Core\Engines\Adapters\BladeAdapter](../../src/Core/Engines/Adapters/BladeAdapter.php)

Blade template engine adapter.

Wraps Laravel's Illuminate/View Blade compiler so Azera applications can
use `.blade.php` templates.  Requires `illuminate/view` to be installed:

```sh
composer require illuminate/view
```

Blade does **not** support pipe-style filters.  Use `addDirective()`
to register custom `@directiveName(...)` syntax instead.

Cache location: `sys_get_temp_dir()/blade_cache` (override with `setCachePath()`)

## Public methods

### __construct() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L30)</small>

`public function __construct(array $vars = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` |  |

**Return value**

- Type: `mixed`


---

### setCachePath() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L46)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

Forces re-initialisation of the Blade compiler on the next render so the
new path takes effect.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L55)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`


---

### flushCache() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L61)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`


---

### addNamespace() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L86)</small>

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Also registers the namespace as a Blade hint path so templates can use
`namespace::view.name` syntax.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**Return value**

- Type: `static`


---

### addDirective() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L109)</small>

`public function addDirective(string $name, callable $handler): static`

Register a custom Blade directive.

Blade does not support pipe-style filters; use this method to add
custom `@name(...)` syntax instead.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Directive name without the `@` prefix. |
| `$handler` | callable | - | fn(?string $expression): string — must return PHP code. |

**Return value**

- Type: `static`


---

### addFilter() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L124)</small>

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Blade does not support pipe-style filters.  Use `addDirective()`
to register a custom `@{$name}` directive instead.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | fn($value, ...$args): mixed |

**Return value**

- Type: `static`

**Throws**

- LogicException  Always.


---

### addFunction() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L141)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Blade does not have a standalone function concept equivalent to
Twig/Plates.  Use `addDirective()` to register a custom
`@{$name}(...)` directive instead.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`

**Throws**

- LogicException  Always.


---

### getDriver() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L157)</small>

`public function getDriver(): mixed`

Return the underlying engine/driver object for advanced configuration.

Returns the underlying `\Illuminate\View\Factory` instance for advanced
configuration.  Initialises Blade on first call if not already done.
Use `getDriver()->getEngineResolver()` or access `$this->bladeCompiler`
via `addDirective()` for compiler-level customisation.

**Return value**

- Type: `mixed`


---

### render() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L228)</small>

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

### renderPartial() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L238)</small>

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

### renderLayout() · <small>[🗎](../../src/Core/Engines/Adapters/BladeAdapter.php#L252)</small>

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
