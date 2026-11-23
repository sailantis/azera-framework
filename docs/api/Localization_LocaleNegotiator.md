# Class: LocaleNegotiator

**Full name:** [Azera\Localization\LocaleNegotiator](../../src/Localization/LocaleNegotiator.php)

Matches a client's accepted languages against the locales an application has.

Pure and side-effect free, making the matching rules straightforward to test
independently of a request or translator. `Request::languages()` parses the
header and applies quality factors; this class selects the available locale
for a list of language tags.

Matching checks accepted tags in the given order (highest quality first):

1. exact tag, case-insensitive and indifferent to `-`/`_` (`de_AT` = `de-AT`),
2. primary subtag (`de-AT` selects an available `de-DE`, or plain `de`),
3. `*` selects the first available locale.

Step 2 compares primary subtags, allowing a region-specific request to select
another available variant of that language before the fallback locale.

## Public methods

### best() · <small>[🗎](../../src/Localization/LocaleNegotiator.php#L32)</small>

`public static function best(array $available, array $accepted, string|null $fallback = null): string|null`

Select the best available locale for a list of accepted languages.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$available` | array | - | Locales the application can serve, in preference order. |
| `$accepted` | array | - | Language tags from `Accept-Language`, highest quality first. |
| `$fallback` | string\|null | `null` | Returned when no accepted tag matches; null means "no preference". |

**Return value**

- Type: `string`|`null`
- Description: An entry of `$available`, or `$fallback`.



---

[Back to the Index ⤴](README.md)
