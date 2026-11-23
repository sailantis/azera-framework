# Localization

Azera uses two localization services, both held by the application context and
both independent of the view engine:

| Service              | For                                              | Accessor             |
| -------------------- | ------------------------------------------------ | -------------------- |
| `Translation`        | Messages, domains, fallback locale               | `$ctx->translator()` |
| `Formatting`         | Numbers, currency, dates, relative time, ICU     | `$ctx->intl()`       |

They share one locale stack, so a scope changes both. The same catalogue and the
same formatting serve controllers, CLI tasks, validators and templates with
Clarity, the native PHP engine, or any adapter.

```php
$ctx->translator()->configure([
    'locale'          => 'en_US',
    'fallback_locale' => 'en_US',
    // Any Clarity loader is accepted directly; Azera adapts the contract.
    'loader'          => new \Clarity\Localization\FileTranslationLoader(__DIR__ . '/../locales'),
]);

$ctx->intl()->configure(['timezone' => 'Europe/Berlin']);

$ctx->translator()->bind($ctx->view());   // t filter + blocks
$ctx->intl()->bind($ctx->view());         // format_* filters
```

A translator can be used immediately: without a loader, it returns keys
unchanged, allowing it to be wired before translations are added.

For messages defined in code — for example, in tests or as a small set of
overrides — configure an array loader directly:

```php
$ctx->translator()->configure([
    'locale' => 'de_DE',
    'loader' => [
        'messages' => [
            'de_DE' => ['greeting' => 'Hallo {name}'],
            'en_US' => ['greeting' => 'Hello {name}'],
        ],
    ],
]);
```

---

## Translating

```php
$ctx->translator()->get('nav.home');                        // "Home"
$ctx->translator()->get('greeting', ['name' => 'Anna']);    // "Hello Anna"
$ctx->translator()->get('title', null, 'books');            // other domain
$ctx->translator()->get('greeting', ['name' => 'Anna'], null, 'de_DE');
```

In a controller, `t()` is sugar for the same call:

```php
class OrderController extends Controller
{
    public function showAction(int $id): array
    {
        return [
            'heading' => $this->t('order.heading', ['id' => $id]),
        ];
    }
}
```

### Uncatalogued keys

When a key is absent from the catalogue, the translator returns it unchanged,
making the key visible where its translation would otherwise appear:

```php
$t->get('not.translated.yet');        // "not.translated.yet"
$t->has('not.translated.yet');        // false
```

When the caller provides fallback text — a validator template or a default
label — use `getOrDefault()` to distinguish a translated value from the
original key:

```php
$t->getOrDefault('order.heading', 'Order {id}', ['id' => 42]);
```

Placeholder values are substituted into `{name}` markers. Lists are joined with
`, `, so `{allowed}` renders the same way a validation error would:

```php
$t->get('in', ['allowed' => ['admin', 'editor']]);   // "must be one of: admin, editor"
```

### Domains

Messages are grouped into domains (`messages`, `validation`, `books`, …). Select
one per call, or for a block:

```php
$t->get('title', null, 'books');

$t->withDomain('emails', function () use ($t) {
    return $t->get('welcome_subject');     // emails domain
});
```

`withDomain()` restores the previous domain when the callback completes or
throws, keeping the domain scope consistent across calls.

---

## Locale

### Precedence

The first available source wins:

| #   | Source                                     | Example                                      |
| --- | ------------------------------------------ | -------------------------------------------- |
| 1   | Locale passed to the call                  | `$t->get('k', null, null, 'it_IT')`          |
| 2   | Enclosing `withLocale()` / `{% with_locale %}` | `$t->withLocale('fr_FR', fn () => …)`     |
| 3   | Locale negotiated from `Accept-Language`   | `$t->negotiate($request, ['en_US', 'de_DE'])` |
| 4   | The service's own `locale` option          | `configure(['locale' => 'de_DE'])`           |
| 5   | The `LocaleService` default                | `new LocaleService(['locale' => 'nb_NO'])`   |
| 6   | Detected environment locale                | —                                            |

Formatting (see [Formatting](#formatting)) uses the same list, minus row 3, because
it reads the same stack.

### Scopes

```php
$t->withLocale('de_DE', fn () => $t->get('greeting'));
```

`withLocale()` is the controller-side equivalent of a `{% with_locale %}` block
and is exception-safe for the same reason as `withDomain()`.

### Negotiating from the request

`Request` already parses `Accept-Language` with its quality factors;
`negotiate()` only decides which available locale that list selects:

```php
// bootstrap, after the request is available
$ctx->translator()->negotiate(
    $ctx->request(),
    available: ['en_US', 'de_DE', 'fr_FR'],
    fallback: 'en_US',
);
```

Matching tries, in the order the client listed the languages:

1. the exact tag, indifferent to case and to `-`/`_` (`de_AT` = `de-at`),
2. the primary subtag, so `de-AT` selects an available `de-DE`,
3. `*`, which selects the first available locale.

A region-specific request can match any available variant of that language
before the fallback locale is selected. `LocaleNegotiator::best()` is pure and
can be called directly from tests or other contexts.

---

## Formatting

Numbers, currency, percentages, dates, relative time and ICU messages come from
`$ctx->intl()` — a second service that shares the translator's locale stack.

```php
$ctx->intl()->configure(['timezone' => 'Europe/Berlin']);

$ctx->intl()->formatCurrency(1234.5, 'EUR');     // "1.234,50 €" in de_DE
$ctx->intl()->formatNumber(0.256, 1);            // "0,3"
$ctx->intl()->percent(0.256, 1);                 // "25,6 %"
$ctx->intl()->formatDate($order->createdAt, 'long');   // "14. November 2023"
$ctx->intl()->formatDateTime($order->createdAt, 'short', 'short');
$ctx->intl()->formatRelative($order->createdAt);       // "3 years ago"
$ctx->intl()->formatMessage('{count, plural, one{# item} other{# items}}', ['count' => 3]);
$ctx->intl()->countryName('DE');                 // "Deutschland"
```

In a controller, `$this->intl()` is the same service.

### One locale, both services

`AppContext` wires `intl()` to `translator()`'s `LocaleService`, so a scope moves
formats *and* messages:

```php
$ctx->translator()->withLocale('en_US', function () use ($ctx) {
    $ctx->translator()->get('balance');            // English message
    $ctx->intl()->formatCurrency(1234.5, 'EUR');   // "$1,234.50"
});
```

The same holds for `{% with_locale %}` in a template and `$ctx->intl()->withLocale()`.
If the two were built separately, call `$ctx->intl()->useTranslator($ctx->translator())`
once in bootstrap.

### The logic lives in Clarity

`Azera\Localization\Intl` delegates to `Clarity\Localization\IntlFormatter`, the
same object behind Clarity's `format_*` filters. Nothing is duplicated, so
`$this->intl()->formatCurrency()` and `{{ 1234.5 |> format_currency("EUR") }}`
produce identical output — including on a build without `ext-intl`, where every
method falls back to a PHP-native equivalent:

```php
$ctx->intl()->hasIntl();   // false → native fallbacks are active
```

### Filters

```php
$ctx->intl()->bind($ctx->view());
```

registers `format_number`, `format_currency`, `currency_name`,
`currency_symbol`, `percent`, `scientific`, `spellout`, `ordinal`,
`format_date`, `format_time`, `format_datetime`, `format_relative`,
`transliterate`, `format_message`, plus the `country_name`, `language_name`,
`locale_name` and `timezone_name` functions.

```twig
{{ 1234.5 |> format_currency("EUR") }}
{{ order.created_at |> format_date("long") }}
{{ "{count, plural, one{# item} other{# items}}" |> format_message({count: n}) }}
{{ country_name("DE") }}
```

Binding both services on one engine is safe: whichever runs first installs the
shared `LocaleService`, and `{% with_locale %}` is declared exactly once.

---

## Templates

### Clarity

```php
$ctx->translator()->bind($ctx->view());
```

registers the `t` filter and both blocks:

```twig
{{ "greeting" |> t({name: user.name}) }}
{{ "title" |> t(domain: "books") }}
{{ "greeting" |> t({name: user.name}, locale: "de_DE") }}

{% with_t_domain "books" %}
    {{ "title" |> t }}
{% endwith_t_domain %}

{% with_locale user:locale %}
    {{ "greeting" |> t }}
{% endwith_locale %}
```

**One locale stack.** `{% with_locale %}` and `$translator->withLocale()` operate
on the *same* `LocaleService`, so a block in a template changes what the
controller sees next, and a controller scope is visible inside the template. This
holds regardless of which side installs first: if the engine already exposes a
`'locale'` service, the translator adopts it; otherwise the translator's service
is installed on the engine.

The formatting service uses that same stack, so `t` and the `format_*` filters
always agree on the locale:

```php
$ctx->translator()->bind($ctx->view());
$ctx->intl()->bind($ctx->view());
```

`AppContext` already gives both services one `LocaleService`, and the shared
`{% with_locale %}` block is declared once no matter which `bind()` runs first.

> **Clarity module integration.** `Clarity\Localization\TranslationModule`,
> `IntlFormatModule` and `LocaleService::registerBlocks()` are typed for
> `Clarity\ClarityEngine`. `Azera\Core\Engines\ClarityEngine` extends the
> framework's `ViewEngine` and incorporates Clarity's trait, so passing one of
> those modules to `$engine->addModule(...)` raises a `TypeError`. Use the
> framework's own `bind()` methods instead — they register the same `t` filter,
> `format_*` filters and blocks through the trait API, backed by the services
> described here.

### Native PHP templates

```php
$ctx->setView(new NativeEngine());
$ctx->view()->setViewPath(__DIR__ . '/../views');
$ctx->translator()->bind($ctx->view());
```

```php
<?php // views/page.php ?>
<h1><?= $this->t('greeting', ['name' => $user->name]) ?></h1>
```

The engine receives `t()` as a function, so `$this->t(...)` resolves through
`NativeEngine::__call()` like any other registered function.

### Other engines

`bind()` falls back to `addFilter('t', …)` for any engine that supports filters
(Twig, Blade, Latte, Plates), so the same translator can back a custom engine.

---

## Translation files

`Clarity\Localization` provides the engine-independent loader layer, including
file, array, chain, and Redis-caching loaders. These loaders share a
single-method contract, which Azera's `TranslationLoaderBridge` adapts.

| Loader                     | Use for                                      |
| -------------------------- | -------------------------------------------- |
| `FileTranslationLoader`    | PHP / JSON / YAML files (`{domain}.{locale}.{ext}`) |
| `ArrayTranslationLoader`   | Translations shipped as code, overrides, tests |
| `ChainTranslationLoader`   | Combining sources; later entries win          |
| `RedisCachingLoader`       | Expensive sources, shared across workers      |

```text
locales/
├── messages.en_US.php
├── messages.de_DE.php
├── validation.de_DE.php
└── books.en_US.yaml
```

Nested tables are flattened with dot separators, so `['nav' => ['home' => …]]`
resolves as `nav.home`.

To override selected messages, combine an array loader with the file loader:

```php
new ChainTranslationLoader(
    new FileTranslationLoader(__DIR__ . '/../locales'),
    new ArrayTranslationLoader([
        'messages' => ['de_DE' => ['greeting' => 'Servus']],
    ]),
);
```

Reach a decorator after an import:

```php
$loader = $ctx->translator()->loader();          // Azera\Localization\LoaderInterface
if ($loader instanceof TranslationLoaderBridge) {
    $inner = $loader->inner();
    if ($inner instanceof RedisCachingLoader) {
        $inner->invalidate('messages');
    }
}
```

### Writing your own loader

```php
use Azera\Localization\LoaderInterface;

final class DatabaseLoader implements LoaderInterface
{
    public function load(string $domain, string $locale): array
    {
        return $this->rows[$domain][$locale] ?? [];   // flat key => message
    }
}
```

The loader contract matches Clarity's, allowing an implementation to work with
both systems.

---

## Validation messages

`Validator::setTranslations()` maps each error code to a catalogue key. The
English template stays as the fallback, so a partial catalogue is fine.

```php
$v = new Validator($request->post());
$v->field('email')->required()->email();
$v->field('name')->string()->min(3);
$v->setTranslations($ctx->translator(), 'validation');   // domain defaults to "validation"

$v->errors();
// ['email' => 'Pflichtfeld', 'name' => 'muss mindestens 3 Zeichen haben']
```

```php
<?php // locales/validation.de_DE.php
return [
    'required'   => 'Pflichtfeld',
    'email'      => 'muss eine gültige E-Mail-Adresse sein',
    'min.string' => 'muss mindestens {min} Zeichen haben',
    'in'         => 'muss einer der folgenden Werte sein: {allowed}',
];
```

Codes (the catalogue keys) and their placeholders:

| Code                                      | Placeholders      |
| ----------------------------------------- | ----------------- |
| `required`                                | —                 |
| `type.int`, `type.float`, `type.bool`     | —                 |
| `email`, `url`, `ip`, `domain`, `pattern` | —                 |
| `min.string`, `min.number`, `min.array`   | `{min}`           |
| `max.string`, `max.number`, `max.array`   | `{max}`           |
| `in`                                      | `{allowed}`       |
| `not_array`, `not_object`                 | —                 |
| `custom`                                  | —                 |

`setTranslations()` is sugar over `setTranslator()`; the most recently
registered translator takes precedence, and a callback can still override
selected codes.

---

## Persistent workers

The locale and domain scopes are request state. `AppContext::clearRequestScope()`
resets them through `RequestScoped::resetState()`, so resident workers
(RoadRunner, FrankenPHP, Volucer) begin each request with locale and domain
scopes reset, including when a render exits with an open `{% with_locale %}`
block.

The catalogue and configuration persist as application state, while locale and
domain scopes are request state.

---

## Testing

For a self-contained translator test, configure `ArrayTranslationLoader` directly:

```php
$t = new Translator([
    'locale' => 'de_DE',
    'loader' => new ArrayTranslationLoader([
        'messages' => ['de_DE' => ['greeting' => 'Hallo {name}']],
    ]),
]);

$this->assertSame('Hallo Anna', $t->get('greeting', ['name' => 'Anna']));
```
