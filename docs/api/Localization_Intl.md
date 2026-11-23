# Class: Intl

**Full name:** [Azera\Localization\Intl](../../src/Localization/Intl.php)

Application-wide locale-aware formatting.

Numbers, currency, percentages, dates, relative time, ICU messages and locale
names — for controllers, mailers, CLI tasks and templates. The formatting
logic itself lives in Clarity's `IntlFormatter`, independently of view
engines. This service manages the active locale, default timezone, and shared
formatter.

```php
$ctx->intl()->configure(['timezone' => 'Europe/Berlin']);

$ctx->intl()->formatCurrency(1234.5, 'EUR');   // "1.234,50 €" in de_DE
$ctx->intl()->formatDate($order->createdAt, 'long');
$ctx->intl()->formatMessage('{count, plural, one{# item} other{# items}}', ['count' => 3]);
```

One locale stack
----------------
The formatter and [`Translator`](Localization_Translator.md) use the same `LocaleService`, so
`{% with_locale %}`, `$translator->withLocale()` and `$intl->withLocale()`
share one locale stack for formatting and translations.

Templates
---------
`bind($view)` registers the `format_*` filters: through Clarity's own
[`IntlModule`](Core_Engines_IntlModule.md) equivalent on a Clarity engine, or as plain filters on any
other engine that supports them.

Native formatting
------------------
Each method has a native fallback in `IntlFormatter`, allowing the
service to format values on PHP builds without `ext-intl`.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/Intl.php#L69)</small>

`public function __construct(array $config = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | `locale`: default locale for formatting. `timezone`: default timezone<br>(`UTC`, `Europe/Berlin`); omitted means `date_default_timezone_get()`.<br>`locale_service`: the shared locale stack, usually the translator's.<br>`formatter`: a pre-built formatter to delegate to. |

**Return value**

- Type: `mixed`


---

### configure() · <small>[🗎](../../src/Localization/Intl.php#L91)</small>

`public function configure(array $config): static`

Apply configuration options.

Updates the options present in `$config`, so the timezone can be set or
changed independently of the locale.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | - |  |

**Return value**

- Type: `static`


---

### formatter() · <small>[🗎](../../src/Localization/Intl.php#L116)</small>

`public function formatter(): Clarity\Localization\IntlFormatter`

Return the formatter this service delegates to.

**Return value**

- Type: `Clarity\Localization\IntlFormatter`


---

### localeService() · <small>[🗎](../../src/Localization/Intl.php#L122)</small>

`public function localeService(): Clarity\Localization\LocaleService`

Return the shared locale stack.

**Return value**

- Type: `Clarity\Localization\LocaleService`


---

### useLocaleService() · <small>[🗎](../../src/Localization/Intl.php#L133)</small>

`public function useLocaleService(Clarity\Localization\LocaleService $service): static`

Adopt a locale service, for example the translator's.

This is what makes formatting follow translation: after this call,
`{% with_locale %}` and `$translator->withLocale()` change both.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$service` | Clarity\Localization\LocaleService | - |  |

**Return value**

- Type: `static`


---

### useTranslator() · <small>[🗎](../../src/Localization/Intl.php#L152)</small>

`public function useTranslator(Azera\Localization\Translator $translator): static`

Adopt the locale stack of a translator.

```php
$ctx->intl()->useTranslator($ctx->translator());
```

The translator is also consulted for its configured locale on every call,
so configuring it later still moves formatting. The formatter's own
fallback is seeded from it for callers that use `formatter()` directly.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$translator` | [Translator](Localization_Translator.md) | - |  |

**Return value**

- Type: `static`


---

### hasIntl() · <small>[🗎](../../src/Localization/Intl.php#L172)</small>

`public function hasIntl(): bool`

Whether formatting uses ICU support.

**Return value**

- Type: `bool`


---

### locale() · <small>[🗎](../../src/Localization/Intl.php#L189)</small>

`public function locale(string|null $locale = null): string`

Resolve the active locale using the translator's precedence.

The translator is consulted live, so configuring it after the service was
built (`$ctx->translator()->configure(['locale' => 'de_DE'])`) still moves
formatting. Without that, formatting would fall back to the machine's
detected locale, which differs between a developer machine and CI.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### setLocale() · <small>[🗎](../../src/Localization/Intl.php#L212)</small>

`public function setLocale(string|null $locale): static`

Set the locale used below an active scope.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `static`


---

### currentLocale() · <small>[🗎](../../src/Localization/Intl.php#L220)</small>

`public function currentLocale(): string|null`

Return the locale an enclosing `withLocale()` scope applies, if any.

**Return value**

- Type: `string`|`null`


---

### pushLocale() · <small>[🗎](../../src/Localization/Intl.php#L226)</small>

`public function pushLocale(string|null $locale): void`

Push a locale onto the shared stack.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `void`


---

### popLocale() · <small>[🗎](../../src/Localization/Intl.php#L232)</small>

`public function popLocale(): void`

Pop the most recently pushed locale.

**Return value**

- Type: `void`


---

### withLocale() · <small>[🗎](../../src/Localization/Intl.php#L248)</small>

`public function withLocale(string $locale, callable $fn): mixed`

Run a callback inside a locale scope.

Uses the same stack as `$translator->withLocale()` and `{% with_locale %}`.
A `finally` block restores the prior scope when the callback completes or
throws.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |
| `$fn` | callable | - |  |

**Return value**

- Type: `mixed`


---

### negotiate() · <small>[🗎](../../src/Localization/Intl.php#L265)</small>

`public function negotiate(Azera\Http\Request $request, array $available, string|null $fallback = null): string|null`

Set the locale negotiated from a request, used before the configured one.

`Translator::negotiate()` also sets the negotiated locale. This method
supports applications that perform locale negotiation through `Intl`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |
| `$available` | array | - |  |
| `$fallback` | string\|null | `null` |  |

**Return value**

- Type: `string`|`null`


---

### formatNumber() · <small>[🗎](../../src/Localization/Intl.php#L288)</small>

`public function formatNumber(mixed $value, int $decimals = 2, string|null $locale = null): string`

Locale-aware decimal number, e.g. `1.234,50`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$decimals` | int | `2` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatCurrency() · <small>[🗎](../../src/Localization/Intl.php#L294)</small>

`public function formatCurrency(mixed $value, string $currency = 'EUR', string|null $locale = null): string`

Locale-aware currency amount, e.g. `1.234,50 €`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$currency` | string | `'EUR'` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### currencyName() · <small>[🗎](../../src/Localization/Intl.php#L300)</small>

`public function currencyName(string $code, string|null $locale = null): string`

Display name for a currency code, e.g. "US-Dollar".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### currencySymbol() · <small>[🗎](../../src/Localization/Intl.php#L306)</small>

`public function currencySymbol(string $code, string|null $locale = null): string`

Symbol for a currency code, e.g. "€".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### percent() · <small>[🗎](../../src/Localization/Intl.php#L312)</small>

`public function percent(mixed $value, int $decimals = 0, string|null $locale = null): string`

Locale-aware percentage, e.g. `25 %`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$decimals` | int | `0` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### scientific() · <small>[🗎](../../src/Localization/Intl.php#L318)</small>

`public function scientific(mixed $value, string|null $locale = null): string`

Scientific notation.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### spellout() · <small>[🗎](../../src/Localization/Intl.php#L324)</small>

`public function spellout(mixed $value, string|null $locale = null): string`

Number in words, e.g. "forty-two".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### ordinal() · <small>[🗎](../../src/Localization/Intl.php#L330)</small>

`public function ordinal(mixed $value, string|null $locale = null): string`

Ordinal number, e.g. "1st".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatDate() · <small>[🗎](../../src/Localization/Intl.php#L336)</small>

`public function formatDate(mixed $value, string $style = 'medium', string|null $locale = null, string|null $timezone = null): string`

Locale-aware date, e.g. `14. November 2023`.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$style` | string | `'medium'` |  |
| `$locale` | string\|null | `null` |  |
| `$timezone` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatTime() · <small>[🗎](../../src/Localization/Intl.php#L346)</small>

`public function formatTime(mixed $value, string $style = 'medium', string|null $locale = null, string|null $timezone = null): string`

Locale-aware time.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$style` | string | `'medium'` |  |
| `$locale` | string\|null | `null` |  |
| `$timezone` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatDateTime() · <small>[🗎](../../src/Localization/Intl.php#L356)</small>

`public function formatDateTime(mixed $value, string $dateStyle = 'medium', string $timeStyle = 'medium', string|null $locale = null, string|null $timezone = null): string`

Locale-aware date and time.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$dateStyle` | string | `'medium'` |  |
| `$timeStyle` | string | `'medium'` |  |
| `$locale` | string\|null | `null` |  |
| `$timezone` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### formatRelative() · <small>[🗎](../../src/Localization/Intl.php#L367)</small>

`public function formatRelative(mixed $value, string|null $locale = null): string`

Relative time, e.g. "3 minutes ago".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### transliterate() · <small>[🗎](../../src/Localization/Intl.php#L373)</small>

`public function transliterate(mixed $value, string $rules = 'Any-Latin; Latin-ASCII'): string`

Transliterate text, e.g. "Hëllo" -> "Hello".

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$value` | mixed | - |  |
| `$rules` | string | `'Any-Latin; Latin-ASCII'` |  |

**Return value**

- Type: `string`


---

### formatMessage() · <small>[🗎](../../src/Localization/Intl.php#L383)</small>

`public function formatMessage(string $pattern, array|null $vars = null, string|null $locale = null): string`

Format an ICU MessageFormat pattern.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$pattern` | string | - |  |
| `$vars` | array\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### countryName() · <small>[🗎](../../src/Localization/Intl.php#L389)</small>

`public function countryName(string $code, string|null $displayLocale = null, string|null $locale = null): string`

ISO country code to display name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### languageName() · <small>[🗎](../../src/Localization/Intl.php#L395)</small>

`public function languageName(string $code, string|null $displayLocale = null, string|null $locale = null): string`

Language code to display name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$code` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### localeName() · <small>[🗎](../../src/Localization/Intl.php#L401)</small>

`public function localeName(string $id, string|null $displayLocale = null, string|null $locale = null): string`

Locale identifier to display name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$id` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### timezoneName() · <small>[🗎](../../src/Localization/Intl.php#L407)</small>

`public function timezoneName(string $timezone, string|null $displayLocale = null): string`

Timezone identifier to display name.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$timezone` | string | - |  |
| `$displayLocale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### bind() · <small>[🗎](../../src/Localization/Intl.php#L425)</small>

`public function bind(Azera\Core\ViewEngine $engine): static`

Register the formatting filters on a view engine.

On a Clarity engine, the `format_*` filters and `with_locale` block are
registered through the trait API and share this service's locale stack.
On other engines, each formatter method is exposed as a filter.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ViewEngine](Core_ViewEngine.md) | - |  |

**Return value**

- Type: `static`


---

### resetState() · <small>[🗎](../../src/Localization/Intl.php#L484)</small>

`public function resetState(): void`

Reset the locale scope at the end of a request.

Called by `AppContext::clearRequestScope()` in a resident worker. The
formatter, its caches, and the configuration persist across requests.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
