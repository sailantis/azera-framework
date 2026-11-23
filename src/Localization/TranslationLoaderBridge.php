<?php

namespace Azera\Localization;

use Clarity\Localization\TranslationLoaderInterface as ClarityLoaderInterface;

/**
 * Adapts between Azera's and Clarity's translation loader contracts.
 *
 * Both interfaces declare the same single method — `load(string $domain,
 * string $locale): array` — so one class can satisfy them at once and bridge
 * in either direction:
 *
 * - **Clarity → Azera**: wrap a `Clarity\Localization\FileTranslationLoader`
 *   (or `Array`, `Chain`, `RedisCaching`) to use it with {@see Translator}.
 * - **Azera → Clarity**: wrap any {@see LoaderInterface} so it can be handed to
 *   Clarity's `TranslationModule`, which is what the framework's
 *   {@see \Azera\Core\Engines\LocalizationModule} does to share one catalogue
 *   between `$translator->get()` and the `t` filter.
 *
 * Each lookup is delegated directly to the wrapped loader, preserving its
 * behavior and state.
 */
final class TranslationLoaderBridge implements LoaderInterface, ClarityLoaderInterface
{
    /**
     * @param LoaderInterface|ClarityLoaderInterface $inner Loader to delegate to.
     */
    public function __construct(private LoaderInterface|ClarityLoaderInterface $inner) {}

    /**
     * @return array<string, string>
     */
    public function load(string $domain, string $locale): array
    {
        return $this->inner->load($domain, $locale);
    }

    /**
     * Return the wrapped loader.
     *
     * Provides access to loader-specific behaviour, such as
     * `RedisCachingLoader::invalidate()`, through the bridge.
     */
    public function inner(): LoaderInterface|ClarityLoaderInterface
    {
        return $this->inner;
    }
}
