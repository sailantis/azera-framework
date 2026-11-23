# Class: Intl

**Full name:** [Azera\Localization\Intl](../../src/Localization/Intl.php)

Application-wide locale-aware formatting.

Numbers, currency, percentages, dates, relative time, ICU messages and locale
names — for controllers, mailers, CLI tasks and templates. The formatting
logic itself lives in Clarity's `IntlFormatter`, which has no engine
dependency; this service owns only what a framework must own: which locale is
active, which timezone is the default, and how the formatter is shared.

```php
$ctx->intl()->configure(['timezone' => 'Europe/Berlin']);

$ctx->intl()->formatCurrency(1234.5, 'EUR');   // "1.234,50 €" in de_DE
$ctx->intl()->formatDate($order->createdAt, 'long');
$ctx->intl()->formatMessage('{count, plural, one{# item} other{# items}}', ['count' => 3]);
```

One locale stack
----------------
The formatter is given the same `LocaleService` the [`Translator`](Localization_Translator.md)
uses, so `{% with_locale %}`, `$translator->withLocale()` and
`$intl->withLocale()` all move the same stack — formatting and translations
cannot disagree about the locale.

Templates
---------
`bind($view)` registers the `format_*` filters: through Clarity's own
[`IntlModule`](Core_Engines_IntlModule.md) equivalent on a Clarity engine, or as plain filters on any
other engine that supports them.

Without `ext-intl`
------------------
Every method has a native fallback (see `IntlFormatter`), so the service
works on a stock PHP build; output degrades but never fails.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/Intl.php#L61)</small>

`public function __construct(array $config = []): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | `[]` | `locale`: default locale for formatting. `timezone`: default timezone<br>(`UTC`, `Europe/Berlin`); omitted means `date_default_timezone_get()`.<br>`locale_service`: the shared locale stack, usually the translator's.<br>`formatter`: a pre-built formatter to delegate to. |

**Return value**

- Type: `mixed`


---

### configure() · <small>[🗎](../../src/Localization/Intl.php#L82)</small>

`public function configure(array $config): static`

Apply configuration options.

Only the keys present are changed, so the timezone can be set later
without restating the locale.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$config` | array | - |  |

**Return value**

- Type: `static`


---

### formatter() · <small>[🗎](../../src/Localization/Intl.php#L106)</small>

`public function formatter(): Clarity\Localization\IntlFormatter`

Return the formatter this service delegates to.

**Return value**

- Type: `Clarity\Localization\IntlFormatter`


---

### localeService() · <small>[🗎](../../src/Localization/Intl.php#L112)</small>

`public function localeService(): Clarity\Localization\LocaleService`

Return the shared locale stack.

**Return value**

- Type: `Clarity\Localization\LocaleService`


---

### useLocaleService() · <small>[🗎](../../src/Localization/Intl.php#L123)</small>

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

### useTranslator() · <small>[🗎](../../src/Localization/Intl.php#L138)</small>

`public function useTranslator(Azera\Localization\Translator $translator): static`

Adopt the locale stack of a translator.

```php
$ctx->intl()->useTranslator($ctx->translator());
```

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$translator` | [Translator](Localization_Translator.md) | - |  |

**Return value**

- Type: `static`


---

### hasIntl() · <small>[🗎](../../src/Localization/Intl.php#L150)</small>

`public function hasIntl(): bool`

Whether ICU formatting is active (false means the native fallbacks run).

**Return value**

- Type: `bool`


---

### locale() · <small>[🗎](../../src/Localization/Intl.php#L160)</small>

`public function locale(string|null $locale = null): string`

Resolve the active locale using the translator's precedence.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | `null` |  |

**Return value**

- Type: `string`


---

### setLocale() · <small>[🗎](../../src/Localization/Intl.php#L166)</small>

`public function setLocale(string|null $locale): static`

Set the locale used below an active scope.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `static`


---

### currentLocale() · <small>[🗎](../../src/Localization/Intl.php#L173)</small>

`public function currentLocale(): string|null`

Return the locale an enclosing `withLocale()` scope applies, if any.

**Return value**

- Type: `string`|`null`


---

### pushLocale() · <small>[🗎](../../src/Localization/Intl.php#L179)</small>

`public function pushLocale(string|null $locale): void`

Push a locale onto the shared stack.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string\|null | - |  |

**Return value**

- Type: `void`


---

### popLocale() · <small>[🗎](../../src/Localization/Intl.php#L185)</small>

`public function popLocale(): void`

Pop the most recently pushed locale.

**Return value**

- Type: `void`


---

### withLocale() · <small>[🗎](../../src/Localization/Intl.php#L200)</small>

`public function withLocale(string $locale, callable $fn): mixed`

Run a callback inside a locale scope.

The same stack as `$translator->withLocale()` and `{% with_locale %}`; the
scope is popped even when the callback throws.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$locale` | string | - |  |
| `$fn` | callable | - |  |

**Return value**

- Type: `mixed`


---

### negotiate() · <small>[🗎](../../src/Localization/Intl.php#L217)</small>

`public function negotiate(Azera\Http\Request $request, array $available, string|null $fallback = null): string|null`

Set the locale negotiated from a request, used before the configured one.

`Translator::negotiate()` already does this on the shared stack; this
method exists for an app that negotiates without a translator.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$request` | [Request](Http_Request.md) | - |  |
| `$available` | array | - |  |
| `$fallback` | string\|null | `null` |  |

**Return value**

- Type: `string`|`null`


---

### formatNumber() · <small>[🗎](../../src/Localization/Intl.php#L240)</small>

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

### formatCurrency() · <small>[🗎](../../src/Localization/Intl.php#L246)</small>

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

### currencyName() · <small>[🗎](../../src/Localization/Intl.php#L252)</small>

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

### currencySymbol() · <small>[🗎](../../src/Localization/Intl.php#L258)</small>

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

### percent() · <small>[🗎](../../src/Localization/Intl.php#L264)</small>

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

### scientific() · <small>[🗎](../../src/Localization/Intl.php#L270)</small>

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

### spellout() · <small>[🗎](../../src/Localization/Intl.php#L276)</small>

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

### ordinal() · <small>[🗎](../../src/Localization/Intl.php#L282)</small>

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

### formatDate() · <small>[🗎](../../src/Localization/Intl.php#L288)</small>

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

### formatTime() · <small>[🗎](../../src/Localization/Intl.php#L298)</small>

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

### formatDateTime() · <small>[🗎](../../src/Localization/Intl.php#L308)</small>

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

### formatRelative() · <small>[🗎](../../src/Localization/Intl.php#L319)</small>

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

### transliterate() · <small>[🗎](../../src/Localization/Intl.php#L325)</small>

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

### formatMessage() · <small>[🗎](../../src/Localization/Intl.php#L335)</small>

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

### countryName() · <small>[🗎](../../src/Localization/Intl.php#L341)</small>

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

### languageName() · <small>[🗎](../../src/Localization/Intl.php#L347)</small>

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

### localeName() · <small>[🗎](../../src/Localization/Intl.php#L353)</small>

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

### timezoneName() · <small>[🗎](../../src/Localization/Intl.php#L359)</small>

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

### bind() · <small>[🗎](../../src/Localization/Intl.php#L377)</small>

`public function bind(Azera\Core\ViewEngine $engine): static`

Register the formatting filters on a view engine.

On a Clarity engine the `format_*` filters and the `with_locale` block are
registered through the trait API, sharing this service's locale stack. On
any other engine each formatter method is exposed as a filter.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$engine` | [ViewEngine](Core_ViewEngine.md) | - |  |

**Return value**

- Type: `static`


---

### resetState() · <small>[🗎](../../src/Localization/Intl.php#L436)</small>

`public function resetState(): void`

Drop the locale scope left by the finished request.

Called by `AppContext::clearRequestScope()` in a resident worker. The
formatter, its caches and the configuration survive.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
