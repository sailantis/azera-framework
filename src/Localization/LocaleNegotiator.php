<?php

namespace Azera\Localization;

/**
 * Matches a client's accepted languages against the locales an application has.
 *
 * Pure and side-effect free, making the matching rules straightforward to test
 * independently of a request or translator. `Request::languages()` parses the
 * header and applies quality factors; this class selects the available locale
 * for a list of language tags.
 *
 * Matching checks accepted tags in the given order (highest quality first):
 *
 * 1. exact tag, case-insensitive and indifferent to `-`/`_` (`de_AT` = `de-AT`),
 * 2. primary subtag (`de-AT` selects an available `de-DE`, or plain `de`),
 * 3. `*` selects the first available locale.
 *
 * Step 2 compares primary subtags, allowing a region-specific request to select
 * another available variant of that language before the fallback locale.
 */
final class LocaleNegotiator
{
    /**
     * Select the best available locale for a list of accepted languages.
     *
     * @param array<int, string> $available Locales the application can serve, in preference order.
     * @param array<int, string> $accepted  Language tags from `Accept-Language`, highest quality first.
     * @param string|null        $fallback  Returned when no accepted tag matches; null means "no preference".
     * @return string|null An entry of `$available`, or `$fallback`.
     */
    public static function best(array $available, array $accepted, ?string $fallback = null): ?string
    {
        if ($available === []) {
            return $fallback;
        }

        $byNormalized = [];
        foreach ($available as $locale) {
            $byNormalized[self::normalize((string) $locale)] = (string) $locale;
        }

        foreach ($accepted as $tag) {
            $normalized = self::normalize((string) $tag);
            if ($normalized === '') {
                continue;
            }

            if ($normalized === '*') {
                return (string) reset($available);
            }

            if (isset($byNormalized[$normalized])) {
                return $byNormalized[$normalized];
            }

            $primary = self::primary($normalized);
            foreach ($byNormalized as $normalizedAvailable => $locale) {
                if (self::primary($normalizedAvailable) === $primary) {
                    return $locale;
                }
            }
        }

        return $fallback;
    }

    /** Lowercase a tag and unify separators, so `de_AT` and `DE-at` compare equal. */
    private static function normalize(string $tag): string
    {
        return strtolower(str_replace('_', '-', trim($tag)));
    }

    /** Return the language part of a normalized tag (`de-AT` → `de`). */
    private static function primary(string $normalized): string
    {
        $pos = strpos($normalized, '-');

        return $pos === false ? $normalized : substr($normalized, 0, $pos);
    }
}
