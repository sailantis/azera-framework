# Interface: LoaderInterface

**Full name:** [Azera\Localization\LoaderInterface](../../src/Localization/LoaderInterface.php)

Source of translation messages for one domain and locale.

Azera's translation service is engine-agnostic and uses this interface as
its loader contract. A loader returns a flat `key => message` map, while
[`Translator`](Localization_Translator.md) handles locale precedence, fallback, and `{placeholder}`
substitution.

Clarity provides four loaders through its own
`Clarity\Localization\TranslationLoaderInterface`. Wrap one in a
[`TranslationLoaderBridge`](Localization_TranslationLoaderBridge.md) to use it with [`Translator::__construct()`](Localization_Translator.md#__construct).

Loaders are called for each lookup. For sources with higher access costs,
such as file I/O or Redis, use Clarity's `RedisCachingLoader` or a PSR-16 cache.

## Public methods

### load() · <small>[🗎](../../src/Localization/LoaderInterface.php#L31)</small>

`public function load(string $domain, string $locale): array`

Load the flat message table for a domain and locale.

Nested catalogues are flattened with dot separators (`nav.home`), and
each value is a string. The loader returns an empty array when the
requested domain or locale has no messages.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
