# Class: IntlModule

**Full name:** [Azera\Core\Engines\IntlModule](../../src/Core/Engines/IntlModule.php)

Binds [`Intl`](Localization_Intl.md) to the Clarity template engine.

Registers Clarity's `format_*` filters, `locale_*` functions and the
`{% with_locale %}` block, all delegating to the framework's formatting
service instead of a Clarity-owned formatter:

```text
{{ 1234.5 |> format_currency("EUR") }}  →  $__c_fn['format_currency'](…)  →  Azera\Localization\Intl
{% with_locale "de_DE" %}               →  $__c_sv['locale']->push("de_DE")
```

The filter list mirrors `Clarity\Localization\IntlFormatModule` exactly; only
the object behind them differs, which is what keeps a controller's
`$ctx->intl()->formatCurrency()` and a template's `|> format_currency`
identical.

Why this does not reuse `IntlFormatModule`
-----------------------------------------
`ModuleInterface::register()` and `LocaleService::registerBlocks()` are typed
against `Clarity\ClarityEngine`. [`ClarityEngine`](Core_Engines_ClarityEngine.md) only *uses* Clarity's
trait — it extends the framework's [`ViewEngine`](Core_ViewEngine.md) — so passing
it to either method throws a `TypeError`. The trait API (`addFilter`,
`addFunction`, `addDirective`, `addService`) carries no such constraint, so
registration goes through it directly.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Engines/IntlModule.php#L38)</small>

`public function __construct(Azera\Localization\Intl $intl): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$intl` | [Intl](Localization_Intl.md) | - | The formatting service to back templates with. |

**Return value**

- Type: `mixed`


---

### registerOn() · <small>[🗎](../../src/Core/Engines/IntlModule.php#L49)</small>

`public function registerOn(object $engine): void`

Register formatting into a Clarity-compatible engine.

Accepts `object` because the engine is Azera's [`ClarityEngine`](Core_Engines_ClarityEngine.md), which
is not a `Clarity\ClarityEngine`. The required methods come from
`Clarity\ClarityEngineTrait`, so the capability check is duck-typed.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | object | - |  |

**Return value**

- Type: `void`

**Throws**

- [InvalidArgumentException](Cache_InvalidArgumentException.md)  When the engine lacks the trait API.



---

[Back to the Index ⤴](README.md)
