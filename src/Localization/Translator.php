<?php

namespace Azera\Localization;

use Azera\Core\Engines\ClarityEngine;
use Azera\Core\Engines\LocalizationModule;
use Azera\Core\Engines\NativeEngine;
use Azera\Core\ViewEngine;
use Azera\Http\Request;
use Azera\Lifecycle\RequestScoped;
use Clarity\Localization\ArrayTranslationLoader;
use Clarity\Localization\LocaleService;
use Clarity\Localization\TranslationLoaderInterface as ClarityLoaderInterface;

/**
 * Application-wide translation service.
 *
 * Centralizes locale selection, domain selection, and missing-key behavior
 * independently of the view engine. Loaders supply the messages (see
 * {@see LoaderInterface}), making the same catalogue available to controllers,
 * validators, CLI tasks, and every view engine.
 *
 * ```php
 * $ctx->translator()->configure([
 *     'locale'          => 'de_DE',
 *     'fallback_locale' => 'en_US',
 *     'loader'          => new \Clarity\Localization\FileTranslationLoader(__DIR__ . '/locales'),
 * ]);
 *
 * $ctx->translator()->get('greeting', ['name' => 'Anna']);   // "Hallo Anna"
 * $ctx->translator()->bind($ctx->view());                     // templates too
 * ```
 *
 * Locale precedence
 * -----------------
 * 1. the locale passed to the call,
 * 2. the enclosing `withLocale()` scope (also `{% with_locale %}` in Clarity),
 * 3. the locale negotiated from `Accept-Language` (see {@see negotiate()}),
 * 4. this service's `locale` option,
 * 5. the `LocaleService` default,
 * 6. the detected environment locale.
 *
 * Steps 2 and 5 are served by Clarity's `LocaleService`, installed on the
 * template engine by {@see LocalizationModule}. One instance is shared, so a
 * `{% with_locale %}` block in a template changes the locale seen by
 * `$translator->get()`, and vice versa.
 *
 * Persistent workers
 * ------------------
 * Locale and domain scopes are request state. {@see resetState()} is called by
 * `AppContext::clearRequestScope()` after each request, so resident workers
 * start each request with fresh scopes, including when a template render throws
 * before its `{% with_locale %}` block closes.
 */
class Translator implements RequestScoped
{
    private ?string $configuredLocale = null;

    private string $fallbackLocale = 'en_US';

    private string $defaultDomain = 'messages';

    private ?string $currentDomain = null;

    /** @var string[] Domains pushed by withDomain(). */
    private array $domainStack = [];

    private LoaderInterface $loader;

    private ?LocaleService $localeService = null;

    /** @var string[] Locale scope used when no LocaleService is installed. */
    private array $localeStack = [];

    /** Locale negotiated from the request, below any withLocale() scope. */
    private ?string $requestLocale = null;

    private string $detectedLocale;

    /**
     * @param array{locale?: string|null, fallback_locale?: string, default_domain?: string, loader?: LoaderInterface|ClarityLoaderInterface|array<string, array<string, array<string, mixed>>>|null} $config
     *     `locale`: default locale for this service. `fallback_locale`: tried when
     *     a key is absent from the requested locale (default `en_US`).
     *     `default_domain`: domain used outside a `withDomain()` scope (default
     *     `messages`). `loader`: any Azera or Clarity loader, or a
     *     `[domain][locale] => messages` array. When omitted, the translator uses
     *     `NullLoader` and returns keys as-is.
     */
    public function __construct(array $config = [])
    {
        $this->detectedLocale = class_exists(LocaleService::class)
            ? LocaleService::detectLocale()
            : 'en_US';

        $this->configure($config);
    }

    /**
     * Apply configuration options.
     *
     * Configuration updates apply to keys supplied in `$config`, allowing the
     * loader to be swapped at runtime while retaining the locale options.
     *
     * @param array{locale?: string|null, fallback_locale?: string, default_domain?: string, loader?: LoaderInterface|ClarityLoaderInterface|array<string, array<string, array<string, mixed>>>|null} $config
     */
    public function configure(array $config): static
    {
        if (\array_key_exists('locale', $config)) {
            $this->configuredLocale = $config['locale'];
        }

        if (isset($config['fallback_locale'])) {
            $this->fallbackLocale = $config['fallback_locale'];
        }

        if (isset($config['default_domain'])) {
            $this->defaultDomain = $config['default_domain'];
        }

        $loader = $config['loader'] ?? null;
        if (\is_array($loader)) {
            // Shorthand for shipping messages as code:
            // ['messages' => ['de_DE' => ['greeting' => 'Hallo']]]
            $loader = new ArrayTranslationLoader($loader);
        }

        if ($loader instanceof LoaderInterface) {
            $this->loader = $loader;
        } elseif ($loader instanceof ClarityLoaderInterface) {
            $this->loader = new TranslationLoaderBridge($loader);
        }

        if (!isset($this->loader)) {
            $this->loader = new NullLoader();
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    /**
     * Translate a key, substituting `{placeholder}` markers.
     *
     * The requested locale is checked first, followed by the fallback locale.
     * If neither catalogue contains the key, the key itself is returned as
     * readable text.
     *
     * @param string              $key    Message key (`nav.home`).
     * @param array<string,mixed>|null $vars Placeholder values for `{name}` substitution.
     * @param string|null         $domain Domain override for this call.
     * @param string|null         $locale Locale override for this call.
     */
    public function get(string $key, ?array $vars = null, ?string $domain = null, ?string $locale = null): string
    {
        return $this->interpolate($this->lookup($key, $domain, $locale) ?? $key, $vars);
    }

    /**
     * Translate a key, using `$default` when no catalogue entry is available.
     *
     * This method distinguishes a translated message from the echoed key,
     * supporting callers that provide fallback text (for example, a
     * validator's English template).
     *
     * @param array<string,mixed>|null $vars
     */
    public function getOrDefault(string $key, string $default, ?array $vars = null, ?string $domain = null, ?string $locale = null): string
    {
        $message = $this->lookup($key, $domain, $locale);

        return $this->interpolate($message ?? $default, $vars);
    }

    /**
     * Whether a key resolves in the given (or active) locale or the fallback.
     */
    public function has(string $key, ?string $domain = null, ?string $locale = null): bool
    {
        return $this->lookup($key, $domain, $locale) !== null;
    }

    /**
     * Return the message table for a domain and locale.
     *
     * A convenience for callers that need the whole catalogue, such as an
     * admin screen listing untranslated keys.
     *
     * @return array<string, string>
     */
    public function catalog(?string $domain = null, ?string $locale = null): array
    {
        return $this->loader->load($domain ?? $this->currentDomain(), $locale ?? $this->locale());
    }

    /** @param array<string,mixed>|null $vars */
    private function interpolate(string $message, ?array $vars): string
    {
        if ($vars === null || $vars === []) {
            return $message;
        }

        $pairs = [];
        foreach ($vars as $name => $value) {
            $pairs['{' . $name . '}'] = $this->stringify($value);
        }

        return \strtr($message, $pairs);
    }

    /**
     * Render a placeholder value as text.
     *
     * Lists use the same formatting as the `in` rule in `Validator::errors()`,
     * so `{allowed}` reads consistently in translated messages and the English
     * template.
     */
    private function stringify(mixed $value): string
    {
        if (\is_array($value)) {
            return \implode(', ', \array_map(fn ($v): string => $this->stringify($v), $value));
        }

        return (string) $value;
    }

    /** Resolve a key against the active and fallback locales, returning null when no message is found. */
    private function lookup(string $key, ?string $domain, ?string $locale): ?string
    {
        $domain ??= $this->currentDomain();
        $locale   = $this->resolveLocale($locale);

        $catalog = $this->loader->load($domain, $locale);
        if (isset($catalog[$key])) {
            return $catalog[$key];
        }

        if ($locale !== $this->fallbackLocale) {
            $fallback = $this->loader->load($domain, $this->fallbackLocale);
            if (isset($fallback[$key])) {
                return $fallback[$key];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Locale
    // -------------------------------------------------------------------------

    /**
     * Resolve the active locale using the documented precedence.
     */
    public function locale(?string $locale = null): string
    {
        return $this->resolveLocale($locale);
    }

    private function resolveLocale(?string $locale): string
    {
        return $locale
            ?? $this->currentLocale()
            ?? $this->requestLocale
            ?? $this->configuredLocale
            ?? $this->localeService?->defaultLocale()
                ?? $this->detectedLocale;
    }

    /** Set the service default locale, below any `withLocale()` scope. */
    public function setLocale(?string $locale): static
    {
        $this->configuredLocale = $locale;
        return $this;
    }

    /** Return the locale configured on this service, if any. */
    public function configuredLocale(): ?string
    {
        return $this->configuredLocale;
    }

    /**
     * Return the locale applied by the enclosing `withLocale()` /
     * `{% with_locale %}` scope, or null when the scope stack is empty.
     */
    public function currentLocale(): ?string
    {
        if ($this->localeService !== null) {
            return $this->localeService->current();
        }

        return $this->localeStack === [] ? null : \end($this->localeStack);
    }

    /**
     * Push a locale onto the shared stack.
     *
     * A null or empty locale pushes the current one, so nesting stays balanced.
     * This is the same stack `{% with_locale %}` uses when the translator is
     * bound to a Clarity engine.
     */
    public function pushLocale(?string $locale): void
    {
        if ($this->localeService !== null) {
            $this->localeService->push($locale);
            return;
        }

        $this->localeStack[] = ($locale ?: \end($this->localeStack)) ?: $this->resolveLocale(null);
    }

    /** Pop the most recently pushed locale when the stack contains one. */
    public function popLocale(): void
    {
        if ($this->localeService !== null) {
            $this->localeService->pop();
            return;
        }

        \array_pop($this->localeStack);
    }

    /**
     * Run a callback inside a locale scope.
     *
     * The equivalent of a `{% with_locale %}` block for controllers, CLI tasks
     * and mailers. A `finally` block restores the prior scope when the callback
     * completes or throws.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withLocale(string $locale, callable $fn): mixed
    {
        $this->pushLocale($locale);

        try {
            return $fn();
        } finally {
            $this->popLocale();
        }
    }

    /**
     * Set the locale negotiated from a request, used before the configured one.
     *
     * Passing null clears the negotiated locale, so subsequent lookups follow
     * the remaining locale precedence.
     */
    public function setRequestLocale(?string $locale): static
    {
        $this->requestLocale = $locale;
        return $this;
    }

    /** Return the currently negotiated request locale, if any. */
    public function requestLocale(): ?string
    {
        return $this->requestLocale;
    }

    /**
     * Negotiate a locale from the request's `Accept-Language` header and adopt it.
     *
     * ```php
     * // in bootstrap
     * $ctx->translator()->negotiate($ctx->request(), ['en_US', 'de_DE', 'fr_FR'], 'en_US');
     * ```
     *
     * @param array<int, string> $available Locales the application can serve.
     * @param string|null        $fallback  Used when no accepted language matches an available locale.
     * @return string|null The adopted locale, or `$fallback`.
     */
    public function negotiate(Request $request, array $available, ?string $fallback = null): ?string
    {
        $accepted = [];
        foreach ($request->languages(true) as $entry) {
            if (isset($entry['language'])) {
                $accepted[] = (string) $entry['language'];
            }
        }

        $locale = LocaleNegotiator::best($available, $accepted, $fallback);
        $this->setRequestLocale($locale);

        return $locale;
    }

    // -------------------------------------------------------------------------
    // Domain
    // -------------------------------------------------------------------------

    /** Return the active domain, or the configured default when no domain scope is active. */
    public function currentDomain(): string
    {
        return $this->currentDomain ?? $this->defaultDomain;
    }

    /** Return the domain used outside a `withDomain()` scope. */
    public function defaultDomain(): string
    {
        return $this->defaultDomain;
    }

    /**
     * Push a domain onto the stack.
     *
     * A null or empty domain pushes the current one, so nesting stays balanced.
     * This is the same stack `{% with_t_domain %}` uses when the translator is
     * bound to a Clarity engine.
     */
    public function pushDomain(?string $domain): void
    {
        $this->domainStack[] = ($domain !== null && $domain !== '') ? $domain : $this->currentDomain();
        $this->currentDomain = \end($this->domainStack);
    }

    /** Pop the most recently pushed domain and select the default when the stack empties. */
    public function popDomain(): void
    {
        \array_pop($this->domainStack);
        $this->currentDomain = $this->domainStack === [] ? null : \end($this->domainStack);
    }

    /**
     * Run a callback inside a domain scope.
     *
     * The equivalent of a `{% with_t_domain %}` block outside templates. A
     * `finally` block restores the prior scope when the callback completes or
     * throws.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withDomain(string $domain, callable $fn): mixed
    {
        $this->pushDomain($domain);

        try {
            return $fn();
        } finally {
            $this->popDomain();
        }
    }

    // -------------------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------------------

    /**
     * Return the loader this translator resolves keys with.
     *
     * Wrapped Clarity loaders stay reachable through
     * {@see TranslationLoaderBridge::inner()} — that is how
     * `RedisCachingLoader::invalidate()` is called after a catalogue changes.
     */
    public function loader(): LoaderInterface
    {
        return $this->loader;
    }

    /**
     * Return the shared locale service, creating it on first use.
     *
     * The instance is the one a bound Clarity engine exposes as its `'locale'`
     * service, so `{% with_locale %}`, `IntlFormatModule` and this translator
     * always read the same stack.
     */
    public function localeService(): ?LocaleService
    {
        if ($this->localeService === null && class_exists(LocaleService::class)) {
            $this->localeService = new LocaleService();
        }

        return $this->localeService;
    }

    /**
     * Adopt a locale service, for example Clarity's already-installed one.
     *
     * Called by {@see LocalizationModule} when the engine already exposes a
     * `'locale'` service, allowing an engine that registered localization first
     * to retain its existing stack as the shared stack.
     */
    public function useLocaleService(LocaleService $service): static
    {
        $this->localeService = $service;
        return $this;
    }

    /**
     * Return the loader in Clarity's shape.
     *
     * Clarity's `TranslationModule`, when used on a plain `Clarity\ClarityEngine`,
     * expects a `TranslationLoaderInterface`; this lets both sides share one
     * catalogue. A loader that implements this interface is returned directly,
     * preserving the existing instance.
     */
    public function clarityLoader(): ClarityLoaderInterface
    {
        if ($this->loader instanceof ClarityLoaderInterface) {
            return $this->loader;
        }

        return new TranslationLoaderBridge($this->loader);
    }

    /**
     * Register translation on a view engine.
     *
     * Registration follows the capabilities of each engine:
     *
     * - Clarity — a {@see LocalizationModule}, which adds the `t` filter and the
     *   `{% with_locale %}` / `{% with_t_domain %}` blocks backed by this service;
     * - `NativeEngine` — a `t()` function, callable as `$this->t('key')` in `.php`
     *   templates;
     * - anything else with a `t` filter (Twig, Blade, Latte, …) — the filter.
     *
     * Repeated calls reuse the engine's installed localization services, keeping
     * registration idempotent.
     *
     * @return $this
     */
    public function bind(ViewEngine $engine): static
    {
        if ($engine instanceof ClarityEngine) {
            (new LocalizationModule($this))->registerOn($engine);
            return $this;
        }

        $translate = fn ($key, ?array $vars = null, ?string $domain = null, ?string $locale = null): string
            => $this->get((string) $key, $vars, $domain, $locale);

        if ($engine instanceof NativeEngine) {
            $engine->addFunction('t', $translate);
            return $this;
        }

        if (\method_exists($engine, 'addFilter')) {
            $engine->addFilter('t', $translate);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Reset request-scoped state.
     *
     * Called by `AppContext::clearRequestScope()` after each request in a
     * resident worker. The catalogue and configuration persist across requests;
     * locale/domain scopes and the negotiated request locale are reset.
     */
    public function resetState(): void
    {
        $this->domainStack   = [];
        $this->currentDomain = null;

        if ($this->localeService !== null) {
            while ($this->localeService->current() !== null) {
                $this->localeService->pop();
            }
        }

        $this->localeStack   = [];
        $this->requestLocale = null;
    }
}
