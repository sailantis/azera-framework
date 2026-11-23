<?php

namespace Azera\Localization;

/**
 * Source of translation messages for one domain and locale.
 *
 * Azera's translation service is engine-agnostic and uses this interface as
 * its loader contract. A loader returns a flat `key => message` map, while
 * {@see Translator} handles locale precedence, fallback, and `{placeholder}`
 * substitution.
 *
 * Clarity provides four loaders through its own
 * `Clarity\Localization\TranslationLoaderInterface`. Wrap one in a
 * {@see TranslationLoaderBridge} to use it with {@see Translator::__construct()}.
 *
 * Loaders are called for each lookup. For sources with higher access costs,
 * such as file I/O or Redis, use Clarity's `RedisCachingLoader` or a PSR-16 cache.
 */
interface LoaderInterface
{
    /**
     * Load the flat message table for a domain and locale.
     *
     * Nested catalogues are flattened with dot separators (`nav.home`), and
     * each value is a string. The loader returns an empty array when the
     * requested domain or locale has no messages.
     *
     * @return array<string, string>
     */
    public function load(string $domain, string $locale): array;
}
