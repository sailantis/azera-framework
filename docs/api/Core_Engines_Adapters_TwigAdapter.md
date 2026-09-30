# Class: TwigAdapter

**Full name:** [Azera\Core\Engines\Adapters\TwigAdapter](../../src/Core/Engines/Adapters/TwigAdapter.php)

Twig template engine adapter.

Wraps Twig/Twig so Azera applications can use `.twig` templates.
Requires `twig/twig` to be installed:

```sh
composer require twig/twig
```

Twig filters are registered natively and are available in templates using
the pipe syntax: `{{ value|filterName }}`.

Cache location: `sys_get_temp_dir()/twig_cache` (override with `setCachePath()`).
Pass an empty string to disable caching.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L35)</small>

`public function __construct(array $vars = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` |  |

**Return value**

- Type: `mixed`


---

### setCachePath() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L51)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

Pass an empty string to disable caching entirely.
Changes take effect immediately even if Twig is already initialised.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L63)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`


---

### flushCache() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L69)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`


---

### addNamespace() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L95)</small>

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Also registers the namespace with the Twig FilesystemLoader so templates
can reference it as `@namespace/path/to/template.twig`.  If Twig has not
been initialised yet the namespace is queued and applied on first render.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**Return value**

- Type: `static`


---

### addFilter() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L119)</small>

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Registers a Twig filter callable.  Available in templates as
`{{ value|name }}` or `{{ value|name(arg1, arg2) }}`.

Can be called before or after the first render.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | fn($value, ...$args): mixed |

**Return value**

- Type: `static`


---

### addFunction() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L137)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Registers a Twig function callable.  Available in templates as
`{{ name(arg1, arg2) }}`.

Can be called before or after the first render.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`


---

### getDriver() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L154)</small>

`public function getDriver(): mixed`

Return the underlying engine/driver object for advanced configuration.

Returns the underlying `\Twig\Environment` instance for advanced
configuration (extensions, token parsers, globals, etc.).
Initialises Twig on first call if not already done.

**Return value**

- Type: `mixed`


---

### render() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L229)</small>

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

### renderPartial() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L239)</small>

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

### renderLayout() · <small>[🗎](../../src/Core/Engines/Adapters/TwigAdapter.php#L253)</small>

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
