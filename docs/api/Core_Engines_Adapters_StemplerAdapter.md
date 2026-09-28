# 🧩 Class: StemplerAdapter

**Full name:** [Azera\Core\Engines\Adapters\StemplerAdapter](../../src/Core/Engines/Adapters/StemplerAdapter.php)

Stempler template engine adapter (Spiral).

Wraps Spiral's Stempler so Azera applications can use `.dark.php` templates.
Requires `spiral/stempler-bridge` to be installed:

```sh
composer require spiral/stempler-bridge
```

Not `spiral/stempler`: that package provides the parser, directives and
visitors, but `Spiral\Stempler\StemplerEngine` — the class instantiated below —
lives in the bridge, which also pulls in `spiral/views` for `ViewLoader`. The
bridge depends on `spiral/stempler`, so requiring it alone is enough; naming
both risks pinning the two out of step.

Stempler mixes HTML and PHP directly: `{{ $var }}` prints, `@foreach(...)`
and `@if(...)` are directives, and inheritance uses `<extends path="..."/>`
plus `<block:name>`. Templates are compiled to PHP classes and, when a cache
directory is configured, persisted there.

The `path` attribute is not a style preference: Spiral's documented
`<extends:layouts/main/>` form is mangled in transit and silently DROPS the
inheritance. The HTML grammar does not treat `:` as a name character, so the
tag's name becomes the text before the colon plus the first character after it
— `extends:layouts/main/` is parsed as a tag named `extendsl`. `ExtendsParent`
then never sees an `extends:` name, no parent is merged, and the raw tag is
printed into the output as `<<mextends:layouts/main/>`. It is not an error, so
the only symptom is a page missing its layout. `ExtendsParent::getPath()` has
a second branch that reads a `path` attribute, and that form parses cleanly
(verified by rendering both against a layout with its own marker).

Unlike Twig/Blade/Plates, `Spiral\Stempler\StemplerEngine` is `final` and has
no bare mode: it takes a container, a config, an optional cache and a loader,
and the directives (`@foreach`, `@if`, …) come from an explicit list rather
than being built in. Spiral normally assembles all of that through its
bootloaders. This adapter performs the same wiring directly — a minimal
container and the directive/visitor set the framework's own
StemplerBootloader registers — so the engine can be measured on its own,
exactly as the other three adapters wrap their engines without their
frameworks.

Cache location: `sys_get_temp_dir()/stempler_cache` (override with
`setCachePath()`). Pass an empty string to disable caching.

## 🚀 Public methods

### __construct() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L81)

`public function __construct(array $vars = []): mixed`

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` |  |

**➡️ Return value**

- Type: mixed


---

### setCachePath() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L111)

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

Pass an empty string to disable caching entirely.

The engine and its cache are built lazily on first render, so calling this
afterwards rebuilds them. Without that, a cache path set after the first
render would be silently ignored — the same trap the Twig adapter
documents.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**➡️ Return value**

- Type: static


---

### getCachePath() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L122)

`public function getCachePath(): string`

Get the currently configured cache directory.

**➡️ Return value**

- Type: string


---

### flushCache() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L135)

`public function flushCache(): static`

Flush all cached compiled templates.

Compiled templates are PHP CLASSES loaded into the current process
(`class_exists` / `eval`), so deleting the cached files is not enough on
its own: a class already declared cannot be redeclared, which is what
makes this a no-op for a process that has already rendered.

**➡️ Return value**

- Type: static


---

### render() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L146)

`public function render(string $view, array $vars = []): string`

Render a view (and optional layout) and return the result.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$view` | string | - | View name to render. |
| `$vars` | array | `[]` | Additional variables for this render call. |

**➡️ Return value**

- Type: string
- Description: Rendered content.


---

### renderPartial() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L159)

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

### renderLayout() · [source](../../src/Core/Engines/Adapters/StemplerAdapter.php#L172)

`public function renderLayout(string $layout, string $content, array $vars = []): string`

Render a layout template wrapping provided content.

Stempler has its own inheritance (`<extends path="..."/>`), so an
Azera-level layout is rendered as an ordinary template with the content
handed to it.

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

[Back to the Index ⤴](README.md)
