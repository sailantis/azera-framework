# 🧩 Class: ClarityEngine

**Full name:** [Azera\Core\Engines\ClarityEngine](../../src/Core/Engines/ClarityEngine.php)

Clarity template engine.

Compiles `.clarity.html` templates into isolated PHP classes that are
cached on disk.  Templates have no access to arbitrary PHP — they can
only use the variables passed to render() and the registered filters.

Usage
-----
```php
$ctx->setView(new ClarityEngine());
$ctx->view()
    ->setPath(__DIR__ . '/../views')
    ->setLayout('layouts/main');

// Register a custom filter
$ctx->view()->addFilter('currency', fn($v) => number_format($v, 2) . ' €');
```

Template extension: .clarity.html  (overridable via setExtension())

Cache location: sys_get_temp_dir()/clarity  (configurable via setCachePath())

## 🚀 Public methods

### __construct() · [source](../../src/Core/Engines/ClarityEngine.php#L36)

`public function __construct(array $vars = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` |  |

**➡️ Return value**

- Type: mixed


---

### setExtension() · [source](../../src/Core/Engines/ClarityEngine.php#L203)

`public function setExtension(string $ext): static`

Set the view file extension for this instance.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$ext` | string | - | Extension with or without a leading dot. |

**➡️ Return value**

- Type: static


---

### getExtension() · [source](../../src/Core/Engines/ClarityEngine.php#L220)

`public function getExtension(): string`

Get the effective file extension used when resolving templates.

**➡️ Return value**

- Type: string
- Description: Extension including leading dot or empty string.


---

### addNamespace() · [source](../../src/Core/Engines/ClarityEngine.php#L234)

`public function addNamespace(string $name, string $path): static`

Add a namespace for view resolution.

Views can be referenced using the syntax "namespace::view.name".

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Namespace name to register. |
| `$path` | string | - | Filesystem path corresponding to the namespace. |

**➡️ Return value**

- Type: static


---

### getNamespaces() · [source](../../src/Core/Engines/ClarityEngine.php#L261)

`public function getNamespaces(): array`

Get the currently registered view namespaces.

**➡️ Return value**

- Type: array
- Description: Associative array of namespace => path mappings.


---

### setViewPath() · [source](../../src/Core/Engines/ClarityEngine.php#L166)

`public function setViewPath(string $path): static`

Set the base path for resolving relative template names.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Base directory for templates. |

**➡️ Return value**

- Type: static


---

### getViewPath() · [source](../../src/Core/Engines/ClarityEngine.php#L192)

`public function getViewPath(): string`

Get the currently configured base path for view resolution.

**➡️ Return value**

- Type: string
- Description: Base directory for views.


---

### render() · [source](../../src/Core/Engines/ClarityEngine.php#L578)

`public function render(string $view, array $vars = []): string`

Render a view template and return the result as a string.

If a layout is configured via setLayout(), the view is first rendered and then
wrapped in the layout. The layout receives the rendered content in the `content`
variable.

Templates are automatically compiled to cached PHP classes. The cache is
automatically invalidated when source files change.

**Basic rendering:**
```php
$html = $engine->render('welcome', [
    'user' => ['name' => 'John', 'email' => 'john@example.com'],
    'title' => 'Welcome Page'
]);
```

**With layout:**
```php
$engine->setLayout('layouts/main');
$html = $engine->render('pages/dashboard', [
    'stats' => $dashboardStats
]);
// The layout receives 'content' variable with rendered 'pages/dashboard'
```

**Without layout (override):**
```php
$engine->setLayout(null); // Temporarily disable layout
$partial = $engine->render('partials/widget', ['data' => $widgetData]);
```

**Namespaced templates:**
```php
$engine->addNamespace('admin', __DIR__ . '/admin_templates');
$html = $engine->render('admin::dashboard', $data);
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to render. Can include namespace prefix (e.g. 'admin::dashboard'). |
| `$vars` | array | `[]` | Variables to pass to the template. Objects are automatically converted to arrays. |

**➡️ Return value**

- Type: string
- Description: Rendered HTML/output.

**⚠️ Throws**

- ClarityException  If template not found or compilation fails.


---

### renderPartial() · [source](../../src/Core/Engines/ClarityEngine.php#L600)

`public function renderPartial(string $view, array $vars = []): string`

Render a partial view (without applying a layout) and return the output.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to resolve and render. |
| `$vars` | array | `[]` | Variables for this render call. |

**➡️ Return value**

- Type: string
- Description: Rendered HTML/output.


---

### renderLayout() · [source](../../src/Core/Engines/ClarityEngine.php#L626)

`public function renderLayout(string $layout, string $content, array $vars = []): string`

Render a layout template wrapping provided content.

The layout receives the rendered view in the `content` variable.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$layout` | string | - | Layout view name. |
| `$content` | string | - | Previously rendered content. |
| `$vars` | array | `[]` | Additional variables to pass to the layout. |

**➡️ Return value**

- Type: string
- Description: Rendered layout output.


---

### addFilter() · [source](../../src/Core/Engines/ClarityEngine.php#L427)

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Filters transform a piped value and are invoked in templates using pipe syntax:
- Simple filter: `{{ value |> filterName }}`
- Filter with arguments: `{{ value |> filterName(arg1, arg2) }}`
- Chained filters: `{{ value |> filter1 |> filter2 |> filter3 }}`

Filters receive the piped value as the first parameter, followed by any arguments
specified in the template.

**Example: Currency filter**
```php
$engine->addFilter('currency', function($amount, string $symbol = '€') {
    return $symbol . ' ' . number_format($amount, 2);
});
```

Template usage:
```twig
{{ price |> currency }}       {# Output: € 99.99 #}
{{ price |> currency('$') }}  {# Output: $ 99.99 #}
```

**Example: Excerpt filter**
```php
$engine->addFilter('excerpt', function($text, int $length = 100) {
    return mb_strlen($text) > $length
        ? mb_substr($text, 0, $length) . '…'
        : $text;
});
```

Template usage:
```twig
{{ article.body |> excerpt(150) }}
```

**Built-in filters:**
- Text: `upper`, `lower`, `trim`, `truncate`, `escape`, `raw`
- Numbers: `number`, `abs`, `round`, `ceil`, `floor`
- Arrays: `join`, `length`, `first`, `last`, `keys`, `values`, `map`, `filter`, `reduce`
- Dates: `date`, `date_modify`, `format_datetime`
- Other: `json`, `default`, `unicode`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | Callable with signature: fn($value, ...$args): mixed |

**➡️ Return value**

- Type: static
- Description: Fluent interface


---

### addFunction() · [source](../../src/Core/Engines/ClarityEngine.php#L443)

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Functions are called directly in templates, e.g. `{{ name(arg) }}`.
This is distinct from filters, which transform a piped value.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**➡️ Return value**

- Type: static


---

### setCachePath() · [source](../../src/Core/Engines/ClarityEngine.php#L507)

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - | Absolute path to the cache directory. |

**➡️ Return value**

- Type: static


---

### getCachePath() · [source](../../src/Core/Engines/ClarityEngine.php#L518)

`public function getCachePath(): string`

Get the currently configured cache directory.

**➡️ Return value**

- Type: string
- Description: Absolute path to the cache directory.


---

### flushCache() · [source](../../src/Core/Engines/ClarityEngine.php#L528)

`public function flushCache(): static`

Flush all cached compiled templates.

**➡️ Return value**

- Type: static


---

### setDebugMode() · [source](../../src/Core/Engines/ClarityEngine.php#L50)

`public function setDebugMode(bool $debug): static`

Enable or disable debug mode (low-level toggle).

Prefer enableDebug() for the full debug experience (context-aware dump(),
dd(), DebugEventBus, optional HTML panel).  setDebugMode(true) only
activates compiler-level assertions (range-loop safety checks) and makes
dump() resolve at runtime instead of being pruned to ''.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$debug` | bool | - | True to enable, false to disable. |

**➡️ Return value**

- Type: static


---

### isDebugMode() · [source](../../src/Core/Engines/ClarityEngine.php#L59)

`public function isDebugMode(): bool`

Return whether debug mode is currently enabled.

**➡️ Return value**

- Type: bool


---

### enableDebug() · [source](../../src/Core/Engines/ClarityEngine.php#L79)

`public function enableDebug(Clarity\Debug\DumpOptions|null $opts = null): static`

Enable full debug mode: context-aware dump()/dd(), DebugEventBus for
loader/compile/render tracing, and optionally an HTML debug panel.

dump() is pruned to '' at compile time in production (zero overhead).
dd() is always active regardless of debug mode.

```php
$engine->enableDebug();   // default options
$engine->enableDebug(new DumpOptions(showPanel: true, maxDepth: 4));
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$opts` | Clarity\Debug\DumpOptions\|null | `null` | Customise depth, masking, panel, etc. |

**➡️ Return value**

- Type: static


---

### disableDebug() · [source](../../src/Core/Engines/ClarityEngine.php#L136)

`public function disableDebug(): static`

Disable debug mode and tear down the event bus and debug panel.

**➡️ Return value**

- Type: static


---

### getDebugBus() · [source](../../src/Core/Engines/ClarityEngine.php#L147)

`public function getDebugBus(): Clarity\Debug\DebugEventBus|null`

Return the active DebugEventBus, or null when debug mode is off.

**➡️ Return value**

- Type: Clarity\Debug\DebugEventBus|null


---

### getDebugPanel() · [source](../../src/Core/Engines/ClarityEngine.php#L155)

`public function getDebugPanel(): Clarity\Debug\HtmlDebugPanel|null`

Return the active HtmlDebugPanel, or null when disabled.

**➡️ Return value**

- Type: Clarity\Debug\HtmlDebugPanel|null


---

### use() · [source](../../src/Core/Engines/ClarityEngine.php#L283)

`public function use(Clarity\ModuleInterface $module): static`

Register a module, granting it access to this engine instance so it can
self-register filters, functions, services, and directives.

Modules are the recommended way to bundle related features (e.g. a full
localization set with filters, a locale stack, and `with_locale` directives).

```php
$engine->use(new \Clarity\LocalizationModule([
    'locale'            => 'de_DE',
    'translations_path' => __DIR__ . '/locales',
]));
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$module` | Clarity\ModuleInterface | - | Module to register. |

**➡️ Return value**

- Type: static


---

### addInlineFilter() · [source](../../src/Core/Engines/ClarityEngine.php#L311)

`public function addInlineFilter(string $name, array $definition): static`

Register an inline filter definition that is compiled directly into the
generated PHP render body (zero runtime call overhead).

The definition must follow the same format as the built-in inline filters:
```php
$engine->addInlineFilter('my_upper', [
    'php' => '\mb_strtoupper((string) {1})',
]);
$engine->addInlineFilter('my_substr', [
    'php' => '\mb_substr((string) {1}, {2}, {3})',
    'params' => ['start', 'length'],
    'defaults' => ['length' => null],
]);
```
Template placeholders: `{1}` for the piped value, `{2}`, `{3}`, … for
additional parameters are declared in `params`.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name. |
| `$definition` | array | - |  |

**➡️ Return value**

- Type: static


---

### addDirective() · [source](../../src/Core/Engines/ClarityEngine.php#L336)

`public function addDirective(string $keyword, callable $handler): static`

Register a handler for a custom directive (e.g. `with_locale`).

The handler is a callable that receives the raw text after the keyword,
source path and line for error messages, and a `$processExpr` callable
that converts a Clarity expression string to a PHP expression string.
It must return a PHP statement string.

```php
$engine->addDirective('with_locale', function(string $rest, string $path, int $line, callable $expr): string {
    return "\$__sv['locale']->push({$expr(trim($rest))});"
});
$engine->addDirective('endwith_locale', fn(...) => "\$__sv['locale']->pop();");
```

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$keyword` | string | - | The directive keyword in lowercase (e.g. 'with_locale'). |
| `$handler` | callable | - | See `Registry` for the expected signature. |

**➡️ Return value**

- Type: static


---

### addService() · [source](../../src/Core/Engines/ClarityEngine.php#L354)

`public function addService(string $name, mixed $service): static`

Store a service object in the registry so that compiled template render
bodies can access it via `$__sv['key']`.

This is primarily used by modules that need shared mutable state (e.g. a
locale stack) accessible both from closures that close over the object
*and* from inline filter PHP templates using `$__sv['key']->method()`.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Key under which the service is accessible. |
| `$service` | mixed | - | Service value, can be of any type. |

**➡️ Return value**

- Type: static


---

### hasService() · [source](../../src/Core/Engines/ClarityEngine.php#L363)

`public function hasService(string $name): bool`

Return true if a service with the given key has been registered.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: bool


---

### getService() · [source](../../src/Core/Engines/ClarityEngine.php#L373)

`public function getService(string $name): mixed`

Retrieve a previously registered service.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**➡️ Return value**

- Type: mixed

**⚠️ Throws**

- RuntimeException  if no service with that name exists.


---

### setLoader() · [source](../../src/Core/Engines/ClarityEngine.php#L455)

`public function setLoader(Clarity\Template\TemplateLoader $loader): static`

Set a custom template loader, replacing the default FileLoader.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$loader` | Clarity\Template\TemplateLoader | - | The loader to use. |

**➡️ Return value**

- Type: static


---

### getLoader() · [source](../../src/Core/Engines/ClarityEngine.php#L468)

`public function getLoader(): Clarity\Template\TemplateLoader`

Return the active template loader, lazily creating a FileLoader if none
has been set explicitly.

**➡️ Return value**

- Type: Clarity\Template\TemplateLoader



---

[Back to the Index ⤴](README.md)
