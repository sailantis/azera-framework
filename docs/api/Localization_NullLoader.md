# Class: NullLoader

**Full name:** [Azera\Localization\NullLoader](../../src/Localization/NullLoader.php)

Loader that returns an empty message table.

This is the default loader before a catalogue is configured on a
[`Translator`](Localization_Translator.md). Each lookup returns an empty table, and untranslated keys
are returned as-is. Clarity provides file, array, chain, and Redis-caching
loaders; wrap one in a [`TranslationLoaderBridge`](Localization_TranslationLoaderBridge.md) to use it with Azera.

## Public methods

### load() · <small>[🗎](../../src/Localization/NullLoader.php#L15)</small>

`public function load(string $domain, string $locale): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$domain` | string | - |  |
| `$locale` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
