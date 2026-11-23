# Class: LocaleBlocks

**Full name:** [Azera\Core\Engines\LocaleBlocks](../../src/Core/Engines/LocaleBlocks.php)

Installs the shared locale stack on a Clarity-compatible engine, once.

Both Azera bridges need the same two things: the engine's `'locale'` service
must be the one the framework uses, and `{% with_locale %}` must be declared
against it. Doing that in each bridge would declare the block twice — Clarity
rejects a duplicate block declaration — so the work lives here and is guarded
by a marker service.

`LocaleService::registerBlocks()` cannot be used: it is typed against
`Clarity\ClarityEngine`, and Azera's engine only *uses* Clarity's trait. The
emitted PHP is identical, so a template cannot tell the difference.

## Public methods

### install() · <small>[🗎](../../src/Core/Engines/LocaleBlocks.php#L37)</small>

`public static function install(object $engine, Clarity\Localization\LocaleService|null $service): Clarity\Localization\LocaleService|null`

Ensure `$engine` exposes `$service` as `'locale'` and declares the block.

If the engine already has a `'locale'` service, that instance wins and is
returned, so whichever bridge registered first owns the shared stack.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | object | - |  |
| `$service` | Clarity\Localization\LocaleService\|null | - |  |

**Return value**

- Type: `Clarity\Localization\LocaleService`|`null`
- Description: The shared stack, or null when the engine has<br>none and `$service` was null.


---

### declare() · <small>[🗎](../../src/Core/Engines/LocaleBlocks.php#L58)</small>

`public static function declare(object $engine): void`

Declare `{% with_locale %}` / `{% endwith_locale %}`, once per engine.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | object | - |  |

**Return value**

- Type: `void`


---

### isDeclared() · <small>[🗎](../../src/Core/Engines/LocaleBlocks.php#L93)</small>

`public static function isDeclared(object $engine): bool`

Whether the block is already declared on the engine.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | object | - |  |

**Return value**

- Type: `bool`



---

[Back to the Index ⤴](README.md)
