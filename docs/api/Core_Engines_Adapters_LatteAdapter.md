# Class: LatteAdapter

**Full name:** [Azera\Core\Engines\Adapters\LatteAdapter](../../src/Core/Engines/Adapters/LatteAdapter.php)

Latte template engine adapter.

Wraps Nette's Latte so Azera applications can use `.latte` templates.
Requires `latte/latte` to be installed:

```sh
composer require latte/latte
```

Latte filters are registered natively and are available in templates using
the pipe syntax: `{$value|filterName}`.

The sandbox is NOT enabled. Latte ships a `SandboxExtension` and a
`Latte\Sandbox\SecurityPolicy`, but both are inert until a policy is set
(`Engine::setPolicy()`), and enabling the sandbox changes the compiled
template — so an adapter that silently turned it on would measure a different
engine than `latte` names. Use `getDriver()` to opt in explicitly:

```php
$adapter->getDriver()
    ->setPolicy(\Latte\Sandbox\SecurityPolicy::createSafePolicy())
    ->setSandboxMode(true);
```

NOTE the name collision: this is *Latte's* `setPolicy()`/`setSandboxMode()`,
not Clarity's. Clarity replaced its own `setSandboxMode()` with a
`Clarity\Engine\Policy` object (see [`ClarityEngine`](Core_Engines_ClarityEngine.md)),
and the two engines' policies are unrelated types.

Cache location: `sys_get_temp_dir()/latte_cache` (override with
`setCachePath()`). Pass an empty string to disable caching.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L52)</small>

`public function __construct(array $vars = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$vars` | array | `[]` |  |

**Return value**

- Type: `mixed`


---

### setCachePath() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L68)</small>

`public function setCachePath(string $path): static`

Set the directory where compiled templates should be cached.

Pass an empty string to disable caching entirely.
Changes take effect immediately even if Latte is already initialised.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$path` | string | - |  |

**Return value**

- Type: `static`


---

### getCachePath() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L78)</small>

`public function getCachePath(): string`

Get the currently configured cache directory.

**Return value**

- Type: `string`


---

### flushCache() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L84)</small>

`public function flushCache(): static`

Flush all cached compiled templates.

**Return value**

- Type: `static`


---

### addFilter() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L111)</small>

`public function addFilter(string $name, callable $fn): static`

Register a custom filter callable.

Registers a Latte filter callable. Available in templates as
`{$value|name}` or `{$value|name: arg1, arg2}`.

Can be called before or after the first render.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Filter name used in templates (e.g. 'currency'). |
| `$fn` | callable | - | fn($value, ...$args): mixed |

**Return value**

- Type: `static`


---

### addFunction() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L129)</small>

`public function addFunction(string $name, callable $fn): static`

Register a custom function callable.

Registers a Latte function callable. Available in templates as
`{name(arg1, arg2)}`.

Can be called before or after the first render.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | Function name used in templates (e.g. 'formatDate'). |
| `$fn` | callable | - | fn(...$args): mixed |

**Return value**

- Type: `static`


---

### getDriver() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L146)</small>

`public function getDriver(): mixed`

Return the underlying engine/driver object for advanced configuration.

Returns the underlying `\Latte\Engine` instance for advanced
configuration (extensions, sandbox policy, syntax, etc.).
Initialises Latte on first call if not already done.

**Return value**

- Type: `mixed`


---

### render() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L261)</small>

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

### renderPartial() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L271)</small>

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

### renderLayout() · <small>[🗎](../../src/Core/Engines/Adapters/LatteAdapter.php#L285)</small>

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
