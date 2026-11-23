# Class: TranslationLoaderBridge

**Full name:** [Azera\Localization\TranslationLoaderBridge](../../src/Localization/TranslationLoaderBridge.php)

Adapts between Azera's and Clarity's translation loader contracts.

Both interfaces declare the same single method — `load(string $domain,
string $locale): array` — so one class can satisfy them at once and bridge
in either direction:

- **Clarity → Azera**: wrap a `Clarity\Localization\FileTranslationLoader`
  (or `Array`, `Chain`, `RedisCaching`) to use it with [`Translator`](Localization_Translator.md).
- **Azera → Clarity**: wrap any [`LoaderInterface`](Localization_LoaderInterface.md) so it can be handed to
  Clarity's `TranslationModule`, which is what the framework's
  [`LocalizationModule`](Core_Engines_LocalizationModule.md) does to share one catalogue
  between `$translator->get()` and the `t` filter.

Each lookup is delegated directly to the wrapped loader, preserving its
behavior and state.

## Public methods

### __construct() · <small>[🗎](../../src/Localization/TranslationLoaderBridge.php#L29)</small>

`public function __construct(Azera\Localization\LoaderInterface|Clarity\Localization\TranslationLoaderInterface $inner): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$inner` | [LoaderInterface](Localization_LoaderInterface.md)\|Clarity\Localization\TranslationLoaderInterface | - | Loader to delegate to. |

**Return value**

- Type: `mixed`


---

### load() · <small>[🗎](../../src/Localization/TranslationLoaderBridge.php#L34)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`


---

### inner() · <small>[🗎](../../src/Localization/TranslationLoaderBridge.php#L45)</small>

`public function inner(): Azera\Localization\LoaderInterface|Clarity\Localization\TranslationLoaderInterface`

Return the wrapped loader.

Provides access to loader-specific behaviour, such as
`RedisCachingLoader::invalidate()`, through the bridge.

**Return value**

- Type: [LoaderInterface](Localization_LoaderInterface.md)|`Clarity\Localization\TranslationLoaderInterface`



---

[Back to the Index ⤴](README.md)
