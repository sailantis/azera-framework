# Class: LocalizationModule

**Full name:** [Azera\Core\Engines\LocalizationModule](../../src/Core/Engines/LocalizationModule.php)

Binds [`Translator`](Localization_Translator.md) to the Clarity template engine.

Registers exactly what Clarity's own localization modules register — the `t`
filter, the `{% with_t_domain %}` block and the `{% with_locale %}` block — but
backed by the framework's translator instead of a Clarity-owned one:

```text
{{ "key" |> t }}        →  $__c_sv['t']->get(...)          (Azera\Localization\Translator)
{% with_t_domain "x" %} →  $__c_sv['t']->pushDomain("x")
{% with_locale "de" %}  →  $__c_sv['locale']->push("de")   (shared LocaleService) * ```

One catalogue, one locale stack
------------------------------
The translator keeps a single `LocaleService`, installed here as the
engine's `'locale'` service, so a template `{% with_locale %}` block changes
the locale seen by `$translator->get()` — and a controller's
`$translator->withLocale()` changes what the template sees. If the engine
already exposes a `'locale'` service, that one wins and the translator adopts
it, so whichever side installs first, the other reuses its stack.

Why this does not reuse `Clarity\Localization\TranslationModule`
---------------------------------------------------------------
Clarity types `ModuleInterface::register(ClarityEngine $engine)` and
`LocaleService::registerBlocks()` against `Clarity\ClarityEngine`.
Azera's [`ClarityEngine`](Core_Engines_ClarityEngine.md) only *uses* Clarity's trait — it extends the
framework's [`ViewEngine`](Core_ViewEngine.md) — so passing it to those methods
throws a `TypeError`. The trait API (`addInlineFilter`, `addDirective`,
`addService`) carries no such constraint, so registration goes through it
directly. The registrations mirror Clarity's modules exactly; only the
service objects differ.

For the same reason this class is deliberately not a Clarity
`ModuleInterface` implementation: it must be callable with the Azera engine.

## Public methods

### __construct() · <small>[🗎](../../src/Core/Engines/LocalizationModule.php#L51)</small>

`public function __construct(Azera\Localization\Translator $translator): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$translator` | [Translator](Localization_Translator.md) | - | The framework translator to back templates with. |

**Return value**

- Type: `mixed`


---

### registerOn() · <small>[🗎](../../src/Core/Engines/LocalizationModule.php#L62)</small>

`public function registerOn(object $engine): void`

Register translation into a Clarity-compatible engine.

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

### clarityLoader() · <small>[🗎](../../src/Core/Engines/LocalizationModule.php#L168)</small>

`public function clarityLoader(): Clarity\Localization\TranslationLoaderInterface`

Return the translator's loader in Clarity's shape.

For callers that need to hand the same catalogue to a Clarity component
elsewhere: a loader that already implements Clarity's interface is passed
through untouched, anything else is wrapped, so nothing is copied.

**Return value**

- Type: `Clarity\Localization\TranslationLoaderInterface`



---

[Back to the Index ⤴](README.md)
