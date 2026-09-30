# Class: NativeEngine

**Full name:** [Azera\Core\Engines\NativeEngine](../../src/Core/Engines/NativeEngine.php)

Native PHP template engine.

Templates are plain `.php` files. Variables are extracted into the local
scope and the file is included directly, making this engine as fast as
hand-written PHP includes.

## Public methods

### addFunction() · <small>[🗎](../../src/Core/Engines/NativeEngine.php#L33)</small>

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

### __call() · <small>[🗎](../../src/Core/Engines/NativeEngine.php#L48)</small>

`public function __call(string $name, array $args): mixed`

Dispatch calls to registered functions from within templates.

Templates are included inside a method scope where `$this` is the
NativeEngine instance, so `$this->myFunc($arg)` naturally routes here
for any name that is not an actual engine method.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |
| `$args` | array | - |  |

**Return value**

- Type: `mixed`

**Throws**

- LogicException  When the function is not registered.


---

### render() · <small>[🗎](../../src/Core/Engines/NativeEngine.php#L65)</small>

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

### renderPartial() · <small>[🗎](../../src/Core/Engines/NativeEngine.php#L87)</small>

`public function renderPartial(string $view, array $vars = []): string`

Render a partial view template and return the generated output.

Variables are merged with global view variables and extracted into the
template scope. Per-call variables override globals.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to resolve and render. |
| `$vars` | array | `[]` | Variables for this render call. |

**Return value**

- Type: `string`
- Description: Rendered HTML/output.

**Throws**

- RuntimeException  If the view file cannot be resolved.


---

### renderLayout() · <small>[🗎](../../src/Core/Engines/NativeEngine.php#L127)</small>

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
