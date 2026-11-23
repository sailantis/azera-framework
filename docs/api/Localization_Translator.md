# Class: Translator

**Full name:** [Azera\Localization\Translator](../../src/Localization/Translator.php)

Application-wide translation service.

Centralizes locale selection, domain selection, and missing-key behavior
independently of the view engine. Loaders supply the messages (see
[`LoaderInterface`](Localization_LoaderInterface.md)), making the same catalogue available to controllers,
validators, CLI tasks, and every view engine.

```php
$ctx->translator()->configure([
    'locale'          => 'de_DE',
    'fallback_locale' => 'en_US',
    'loader'          => new \Clarity\Localization\FileTranslationLoader(__DIR__ . '/locales'),
]);

$ctx->translator()->get('greeting', ['name' => 'Anna']);   // "Hallo Anna"
$ctx->translator()->bind($ctx->view());                     // templates too
```

Locale precedence
-----------------
1. the locale passed to the call,
2. the enclosing `withLocale()` scope (also `{% with_locale %}` in Clarity),
3. the locale negotiated from `Accept-Language` (see `negotiate()`),
4. this service's `locale` option,
5. the `LocaleService` default,
6. the detected environment locale.

Steps 2 and 5 are served by Clarity's `LocaleService`, installed on the
template engine by [`LocalizationModule`](Core_Engines_LocalizationModule.md). One instance is shared, so a
`{% with_locale %}` block in a template changes the locale seen by
`$translator->get()`, and vice versa.

Persistent workers
------------------
Locale and domain scopes are request state. `resetState()` is called by
`AppContext::clearRequestScope()` after each request, so resident workers
start each request with fresh scopes, including when a template render throws
before its `{% with_locale %}` block closes.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/Translator.php#L89)</small>

`public function __construct(array $config = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | `locale`: default locale for this service. `fallback_locale`: tried when<br>a key is absent from the requested locale (default `en_US`).<br>`default_domain`: domain used outside a `withDomain()` scope (default<br>`messages`). `loader`: any Azera or Clarity loader, or a<br>`[domain][locale] => messages` array. When omitted, the translator uses<br>`NullLoader` and returns keys as-is. |

**Return value**

- Type: `mixed`


---

### configure() · <small>[🗎](../../src/Localization/Translator.php#L106)</small>

`public function configure(array $config): static`

Apply configuration options.

Configuration updates apply to keys supplied in `$config`, allowing the
loader to be swapped at runtime while retaining the locale options.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | - |  |

**Return value**

- Type: `static`


---

### get() · <small>[🗎](../../src/Localization/Translator.php#L156)</small>

`public function get(string $key, array|null $vars = null, string|null $domain = null, string|null $locale = null): string`

Translate a key, substituting `{placeholder}` markers.

The requested locale is checked first, followed by the fallback locale.
If neither catalogue contains the key, the key itself is returned as
readable text.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - | Message key (`nav.home`). |
| `$vars` | array\|null | `null` | Placeholder values for `{name}` substitution. |
| `$domain` | string\|null | `null` | Domain override for this call. |
| `$locale` | string\|null | `null` | Locale override for this call. |

**Return value**

- Type: `string`


---

### getOrDefault() · <small>[🗎](../../src/Localization/Translator.php#L170)</small>

`public function getOrDefault(string $key, string $default, array|null $vars = null, string|null $domain = null, string|null $locale = null): string`

Translate a key, using `$default` when no catalogue entry is available.

This method distinguishes a translated message from the echoed key,
supporting callers that provide fallback text (for example, a
validator's English template).

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |
| `$default` | string | - |  |
| `$vars` | array\|null | `null` |  |
| `$domain` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### has() · <small>[🗎](../../src/Localization/Translator.php#L180)</small>

`public function has(string $key, string|null $domain = null, string|null $locale = null): bool`

Whether a key resolves in the given (or active) locale or the fallback.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$key` | string | - |  |
| `$domain` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `bool`


---

### catalog() · <small>[🗎](../../src/Localization/Translator.php#L193)</small>

`public function catalog(string|null $domain = null, string|null $locale = null): array`

Return the message table for a domain and locale.

A convenience for callers that need the whole catalogue, such as an
admin screen listing untranslated keys.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `array`


---

### locale() · <small>[🗎](../../src/Localization/Translator.php#L257)</small>

`public function locale(string|null $locale = null): string`

Resolve the active locale using the documented precedence.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### setLocale() · <small>[🗎](../../src/Localization/Translator.php#L273)</small>

`public function setLocale(string|null $locale): static`

Set the service default locale, below any `withLocale()` scope.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `static`


---

### configuredLocale() · <small>[🗎](../../src/Localization/Translator.php#L280)</small>

`public function configuredLocale(): string|null`

Return the locale configured on this service, if any.

**Return value**

- Type: `string`|`null`


---

### currentLocale() · <small>[🗎](../../src/Localization/Translator.php#L289)</small>

`public function currentLocale(): string|null`

Return the locale applied by the enclosing `withLocale()` /
`{% with_locale %}` scope, or null when the scope stack is empty.

**Return value**

- Type: `string`|`null`


---

### pushLocale() · <small>[🗎](../../src/Localization/Translator.php#L305)</small>

`public function pushLocale(string|null $locale): void`

Push a locale onto the shared stack.

A null or empty locale pushes the current one, so nesting stays balanced.
This is the same stack `{% with_locale %}` uses when the translator is
bound to a Clarity engine.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `void`


---

### popLocale() · <small>[🗎](../../src/Localization/Translator.php#L316)</small>

`public function popLocale(): void`

Pop the most recently pushed locale when the stack contains one.

**Return value**

- Type: `void`


---

### withLocale() · <small>[🗎](../../src/Localization/Translator.php#L337)</small>

`public function withLocale(string $locale, callable $fn): mixed`

Run a callback inside a locale scope.

The equivalent of a `{% with_locale %}` block for controllers, CLI tasks
and mailers. A `finally` block restores the prior scope when the callback
completes or throws.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |
| `$fn` | callable | - |  |

**Return value**

- Type: `mixed`


---

### setRequestLocale() · <small>[🗎](../../src/Localization/Translator.php#L354)</small>

`public function setRequestLocale(string|null $locale): static`

Set the locale negotiated from a request, used before the configured one.

Passing null clears the negotiated locale, so subsequent lookups follow
the remaining locale precedence.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `static`


---

### requestLocale() · <small>[🗎](../../src/Localization/Translator.php#L361)</small>

`public function requestLocale(): string|null`

Return the currently negotiated request locale, if any.

**Return value**

- Type: `string`|`null`


---

### negotiate() · <small>[🗎](../../src/Localization/Translator.php#L378)</small>

`public function negotiate(Azera\Http\Request $request, array $available, string|null $fallback = null): string|null`

Negotiate a locale from the request's `Accept-Language` header and adopt it.

```php
// in bootstrap
$ctx->translator()->negotiate($ctx->request(), ['en_US', 'de_DE', 'fr_FR'], 'en_US');
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |
| `$available` | array | - | Locales the application can serve. |
| `$fallback` | string\|null | `null` | Used when no accepted language matches an available locale. |

**Return value**

- Type: `string`|`null`
- Description: The adopted locale, or `$fallback`.


---

### currentDomain() · <small>[🗎](../../src/Localization/Translator.php#L398)</small>

`public function currentDomain(): string`

Return the active domain, or the configured default when no domain scope is active.

**Return value**

- Type: `string`


---

### defaultDomain() · <small>[🗎](../../src/Localization/Translator.php#L404)</small>

`public function defaultDomain(): string`

Return the domain used outside a `withDomain()` scope.

**Return value**

- Type: `string`


---

### pushDomain() · <small>[🗎](../../src/Localization/Translator.php#L416)</small>

`public function pushDomain(string|null $domain): void`

Push a domain onto the stack.

A null or empty domain pushes the current one, so nesting stays balanced.
This is the same stack `{% with_t_domain %}` uses when the translator is
bound to a Clarity engine.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string\|null | - |  |

**Return value**

- Type: `void`


---

### popDomain() · <small>[🗎](../../src/Localization/Translator.php#L423)</small>

`public function popDomain(): void`

Pop the most recently pushed domain and select the default when the stack empties.

**Return value**

- Type: `void`


---

### withDomain() · <small>[🗎](../../src/Localization/Translator.php#L440)</small>

`public function withDomain(string $domain, callable $fn): mixed`

Run a callback inside a domain scope.

The equivalent of a `{% with_t_domain %}` block outside templates. A
`finally` block restores the prior scope when the callback completes or
throws.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$fn` | callable | - |  |

**Return value**

- Type: `mixed`


---

### loader() · <small>[🗎](../../src/Localization/Translator.php#L462)</small>

`public function loader(): Azera\Localization\LoaderInterface`

Return the loader this translator resolves keys with.

Wrapped Clarity loaders stay reachable through
[`TranslationLoaderBridge::inner()`](Localization_TranslationLoaderBridge.md#inner) — that is how
`RedisCachingLoader::invalidate()` is called after a catalogue changes.

**Return value**

- Type: [LoaderInterface](Localization_LoaderInterface.md)


---

### localeService() · <small>[🗎](../../src/Localization/Translator.php#L474)</small>

`public function localeService(): Clarity\Localization\LocaleService|null`

Return the shared locale service, creating it on first use.

The instance is the one a bound Clarity engine exposes as its `'locale'`
service, so `{% with_locale %}`, `IntlFormatModule` and this translator
always read the same stack.

**Return value**

- Type: `Clarity\Localization\LocaleService`|`null`


---

### useLocaleService() · <small>[🗎](../../src/Localization/Translator.php#L490)</small>

`public function useLocaleService(Clarity\Localization\LocaleService $service): static`

Adopt a locale service, for example Clarity's already-installed one.

Called by [`LocalizationModule`](Core_Engines_LocalizationModule.md) when the engine already exposes a
`'locale'` service, allowing an engine that registered localization first
to retain its existing stack as the shared stack.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$service` | Clarity\Localization\LocaleService | - |  |

**Return value**

- Type: `static`


---

### clarityLoader() · <small>[🗎](../../src/Localization/Translator.php#L504)</small>

`public function clarityLoader(): Clarity\Localization\TranslationLoaderInterface`

Return the loader in Clarity's shape.

Clarity's `TranslationModule`, when used on a plain `Clarity\ClarityEngine`,
expects a `TranslationLoaderInterface`; this lets both sides share one
catalogue. A loader that implements this interface is returned directly,
preserving the existing instance.

**Return value**

- Type: `Clarity\Localization\TranslationLoaderInterface`


---

### bind() · <small>[🗎](../../src/Localization/Translator.php#L529)</small>

`public function bind(Azera\Core\ViewEngine $engine): static`

Register translation on a view engine.

Registration follows the capabilities of each engine:

- Clarity — a [`LocalizationModule`](Core_Engines_LocalizationModule.md), which adds the `t` filter and the
  `{% with_locale %}` / `{% with_t_domain %}` blocks backed by this service;
- `NativeEngine` — a `t()` function, callable as `$this->t('key')` in `.php`
  templates;
- anything else with a `t` filter (Twig, Blade, Latte, …) — the filter.

Repeated calls reuse the engine's installed localization services, keeping
registration idempotent.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ViewEngine](Core_ViewEngine.md) | - |  |

**Return value**

- Type: `static`


---

### resetState() · <small>[🗎](../../src/Localization/Translator.php#L562)</small>

`public function resetState(): void`

Reset request-scoped state.

Called by `AppContext::clearRequestScope()` after each request in a
resident worker. The catalogue and configuration persist across requests;
locale/domain scopes and the negotiated request locale are reset.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
