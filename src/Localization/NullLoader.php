<?php

namespace Azera\Localization;

/**
 * Loader that returns an empty message table.
 *
 * This is the default loader before a catalogue is configured on a
 * {@see Translator}. Each lookup returns an empty table, and untranslated keys
 * are returned as-is. Clarity provides file, array, chain, and Redis-caching
 * loaders; wrap one in a {@see TranslationLoaderBridge} to use it with Azera.
 */
final class NullLoader implements LoaderInterface
{
    public function load(string $domain, string $locale): array
    {
        return [];
    }
}
