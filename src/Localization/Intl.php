<?php

namespace Azera\Localization;

use Azera\Core\Engines\ClarityEngine;
use Azera\Core\Engines\IntlModule;
use Azera\Core\ViewEngine;
use Azera\Http\Request;
use Azera\Lifecycle\RequestScoped;
use Clarity\Localization\IntlFormatter;
use Clarity\Localization\LocaleService;

/**
 * Application-wide locale-aware formatting.
 *
 * Numbers, currency, percentages, dates, relative time, ICU messages and locale
 * names — for controllers, mailers, CLI tasks and templates. The formatting
 * logic itself lives in Clarity's {@see IntlFormatter}, independently of view
 * engines. This service manages the active locale, default timezone, and shared
 * formatter.
 *
 * ```php
 * $ctx->intl()->configure(['timezone' => 'Europe/Berlin']);
 *
 * $ctx->intl()->formatCurrency(1234.5, 'EUR');   // "1.234,50 €" in de_DE
 * $ctx->intl()->formatDate($order->createdAt, 'long');
 * $ctx->intl()->formatMessage('{count, plural, one{# item} other{# items}}', ['count' => 3]);
 * ```
 *
 * One locale stack
 * ----------------
 * The formatter and {@see Translator} use the same {@see LocaleService}, so
 * `{% with_locale %}`, `$translator->withLocale()` and `$intl->withLocale()`
 * share one locale stack for formatting and translations.
 *
 * Templates
 * ---------
 * `bind($view)` registers the `format_*` filters: through Clarity's own
 * {@see IntlModule} equivalent on a Clarity engine, or as plain filters on any
 * other engine that supports them.
 *
 * Native formatting
 * ------------------
 * Each method has a native fallback in {@see IntlFormatter}, allowing the
 * service to format values on PHP builds without `ext-intl`.
 */
class Intl implements RequestScoped
{
    private IntlFormatter $formatter;

    private LocaleService $localeService;

    /** Locale configured on this service, below any scope and negotiated locale. */
    private ?string $configuredLocale = null;

    /** Locale negotiated from a request, below any scope and above the configured one. */
    private ?string $requestLocale = null;

    /** Translator whose configured locale formatting follows, if one was adopted. */
    private ?Translator $translator = null;

    /**
     * @param array{locale?: string|null, timezone?: string|null, locale_service?: LocaleService|null, formatter?: IntlFormatter|null} $config
     *     `locale`: default locale for formatting. `timezone`: default timezone
     *     (`UTC`, `Europe/Berlin`); omitted means `date_default_timezone_get()`.
     *     `locale_service`: the shared locale stack, usually the translator's.
     *     `formatter`: a pre-built formatter to delegate to.
     */
    public function __construct(array $config = [])
    {
        $this->localeService    = $config['locale_service'] ?? new LocaleService();
        $this->configuredLocale = $config['locale'] ?? null;
        $this->formatter        = $config['formatter'] ?? new IntlFormatter(
            $this->configuredLocale,
            $config['timezone'] ?? null,
            $this->localeService,
        );

        // Keep the formatter connected to the shared locale stack.
        $this->formatter->useLocaleService($this->localeService);
    }

    /**
     * Apply configuration options.
     *
     * Updates the options present in `$config`, so the timezone can be set or
     * changed independently of the locale.
     *
     * @param array{locale?: string|null, timezone?: string|null, locale_service?: LocaleService|null, formatter?: IntlFormatter|null} $config
     */
    public function configure(array $config): static
    {
        if (\array_key_exists('locale', $config)) {
            $this->configuredLocale = $config['locale'];
            $this->formatter->setLocale($config['locale']);
        }

        if (isset($config['timezone'])) {
            $this->formatter->setTimezone($config['timezone']);
        }

        if (isset($config['locale_service'])) {
            $this->localeService = $config['locale_service'];
            $this->formatter->useLocaleService($this->localeService);
        }

        if (isset($config['formatter'])) {
            $this->formatter = $config['formatter'];
            $this->formatter->useLocaleService($this->localeService);
        }

        return $this;
    }

    /** Return the formatter this service delegates to. */
    public function formatter(): IntlFormatter
    {
        return $this->formatter;
    }

    /** Return the shared locale stack. */
    public function localeService(): LocaleService
    {
        return $this->localeService;
    }

    /**
     * Adopt a locale service, for example the translator's.
     *
     * This is what makes formatting follow translation: after this call,
     * `{% with_locale %}` and `$translator->withLocale()` change both.
     */
    public function useLocaleService(LocaleService $service): static
    {
        $this->localeService = $service;
        $this->formatter->useLocaleService($service);

        return $this;
    }

    /**
     * Adopt the locale stack of a translator.
     *
     * ```php
     * $ctx->intl()->useTranslator($ctx->translator());
     * ```
     *
     * The translator is also consulted for its configured locale on every call,
     * so configuring it later still moves formatting. The formatter's own
     * fallback is seeded from it for callers that use `formatter()` directly.
     */
    public function useTranslator(Translator $translator): static
    {
        $this->translator = $translator;

        $service = $translator->localeService();

        if ($service !== null) {
            $this->useLocaleService($service);
        }

        // The formatter keeps its own fallback for callers that use it directly;
        // Intl::locale() applies the translator's live configuration per call.
        if ($this->configuredLocale === null) {
            $this->formatter->setLocale($translator->configuredLocale());
        }

        return $this;
    }

    /** Whether formatting uses ICU support. */
    public function hasIntl(): bool
    {
        return $this->formatter->hasIntl();
    }

    // -------------------------------------------------------------------------
    // Locale
    // -------------------------------------------------------------------------

    /**
     * Resolve the active locale using the translator's precedence.
     *
     * The translator is consulted live, so configuring it after the service was
     * built (`$ctx->translator()->configure(['locale' => 'de_DE'])`) still moves
     * formatting. Without that, formatting would fall back to the machine's
     * detected locale, which differs between a developer machine and CI.
     */
    public function locale(?string $locale = null): string
    {
        return $locale ?? $this->resolveLocale();
    }

    private function resolveLocale(): string
    {
        return $this->localeService->current()
            ?? $this->requestLocale
            ?? $this->translator?->requestLocale()
            ?? $this->configuredLocale
            ?? $this->translator?->configuredLocale()
            ?? $this->localeService->defaultLocale()
            ?? LocaleService::detectLocale();
    }

    /** Locale for a formatting call: the argument, then the resolved active one. */
    private function loc(?string $locale): string
    {
        return $locale ?? $this->resolveLocale();
    }

    /** Set the locale used below an active scope. */
    public function setLocale(?string $locale): static
    {
        $this->configuredLocale = $locale;
        $this->formatter->setLocale($locale);
        return $this;
    }

    /** Return the locale an enclosing `withLocale()` scope applies, if any. */
    public function currentLocale(): ?string
    {
        return $this->localeService->current();
    }

    /** Push a locale onto the shared stack. */
    public function pushLocale(?string $locale): void
    {
        $this->localeService->push($locale);
    }

    /** Pop the most recently pushed locale. */
    public function popLocale(): void
    {
        $this->localeService->pop();
    }

    /**
     * Run a callback inside a locale scope.
     *
     * Uses the same stack as `$translator->withLocale()` and `{% with_locale %}`.
     * A `finally` block restores the prior scope when the callback completes or
     * throws.
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
     * `Translator::negotiate()` also sets the negotiated locale. This method
     * supports applications that perform locale negotiation through `Intl`.
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

        if ($locale !== null) {
            $this->requestLocale = $locale;
        }

        return $locale;
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    /** Locale-aware decimal number, e.g. `1.234,50`. */
    public function formatNumber(mixed $value, int $decimals = 2, ?string $locale = null): string
    {
        return $this->formatter->formatNumber($value, $decimals, $this->loc($locale));
    }

    /** Locale-aware currency amount, e.g. `1.234,50 €`. */
    public function formatCurrency(mixed $value, string $currency = 'EUR', ?string $locale = null): string
    {
        return $this->formatter->formatCurrency($value, $currency, $this->loc($locale));
    }

    /** Display name for a currency code, e.g. "US-Dollar". */
    public function currencyName(string $code, ?string $locale = null): string
    {
        return $this->formatter->currencyName($code, $this->loc($locale));
    }

    /** Symbol for a currency code, e.g. "€". */
    public function currencySymbol(string $code, ?string $locale = null): string
    {
        return $this->formatter->currencySymbol($code, $this->loc($locale));
    }

    /** Locale-aware percentage, e.g. `25 %`. */
    public function percent(mixed $value, int $decimals = 0, ?string $locale = null): string
    {
        return $this->formatter->percent($value, $decimals, $this->loc($locale));
    }

    /** Scientific notation. */
    public function scientific(mixed $value, ?string $locale = null): string
    {
        return $this->formatter->scientific($value, $this->loc($locale));
    }

    /** Number in words, e.g. "forty-two". */
    public function spellout(mixed $value, ?string $locale = null): string
    {
        return $this->formatter->spellout($value, $this->loc($locale));
    }

    /** Ordinal number, e.g. "1st". */
    public function ordinal(mixed $value, ?string $locale = null): string
    {
        return $this->formatter->ordinal($value, $this->loc($locale));
    }

    /** Locale-aware date, e.g. `14. November 2023`. */
    public function formatDate(
        mixed $value,
        string $style = 'medium',
        ?string $locale = null,
        ?string $timezone = null,
    ): string {
        return $this->formatter->formatDate($value, $style, $this->loc($locale), $timezone);
    }

    /** Locale-aware time. */
    public function formatTime(
        mixed $value,
        string $style = 'medium',
        ?string $locale = null,
        ?string $timezone = null,
    ): string {
        return $this->formatter->formatTime($value, $style, $this->loc($locale), $timezone);
    }

    /** Locale-aware date and time. */
    public function formatDateTime(
        mixed $value,
        string $dateStyle = 'medium',
        string $timeStyle = 'medium',
        ?string $locale = null,
        ?string $timezone = null,
    ): string {
        return $this->formatter->formatDateTime($value, $dateStyle, $timeStyle, $this->loc($locale), $timezone);
    }

    /** Relative time, e.g. "3 minutes ago". */
    public function formatRelative(mixed $value, ?string $locale = null): string
    {
        return $this->formatter->formatRelative($value, $this->loc($locale));
    }

    /** Transliterate text, e.g. "Hëllo" -> "Hello". */
    public function transliterate(mixed $value, string $rules = 'Any-Latin; Latin-ASCII'): string
    {
        return $this->formatter->transliterate($value, $rules);
    }

    /**
     * Format an ICU MessageFormat pattern.
     *
     * @param array<string, mixed>|null $vars
     */
    public function formatMessage(string $pattern, ?array $vars = null, ?string $locale = null): string
    {
        return $this->formatter->formatMessage($pattern, $vars, $this->loc($locale));
    }

    /** ISO country code to display name. */
    public function countryName(string $code, ?string $displayLocale = null, ?string $locale = null): string
    {
        return $this->formatter->countryName($code, $displayLocale, $this->loc($locale));
    }

    /** Language code to display name. */
    public function languageName(string $code, ?string $displayLocale = null, ?string $locale = null): string
    {
        return $this->formatter->languageName($code, $displayLocale, $this->loc($locale));
    }

    /** Locale identifier to display name. */
    public function localeName(string $id, ?string $displayLocale = null, ?string $locale = null): string
    {
        return $this->formatter->localeName($id, $displayLocale, $this->loc($locale));
    }

    /** Timezone identifier to display name. */
    public function timezoneName(string $timezone, ?string $displayLocale = null): string
    {
        return $this->formatter->timezoneName($timezone, $displayLocale);
    }

    // -------------------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------------------

    /**
     * Register the formatting filters on a view engine.
     *
     * On a Clarity engine, the `format_*` filters and `with_locale` block are
     * registered through the trait API and share this service's locale stack.
     * On other engines, each formatter method is exposed as a filter.
     *
     * @return $this
     */
    public function bind(ViewEngine $engine): static
    {
        if ($engine instanceof ClarityEngine) {
            (new IntlModule($this))->registerOn($engine);
            return $this;
        }

        if (!\method_exists($engine, 'addFilter')) {
            return $this;
        }

        $format = [
            'format_number'   => fn (mixed $v, int $decimals = 2, ?string $locale = null): string
                => $this->formatNumber($v, $decimals, $locale),
            'format_currency' => fn (mixed $v, string $currency = 'EUR', ?string $locale = null): string
                => $this->formatCurrency($v, $currency, $locale),
            'currency_name'   => fn (string $code, ?string $locale = null): string
                => $this->currencyName($code, $locale),
            'currency_symbol' => fn (string $code, ?string $locale = null): string
                => $this->currencySymbol($code, $locale),
            'percent'         => fn (mixed $v, int $decimals = 0, ?string $locale = null): string
                => $this->percent($v, $decimals, $locale),
            'scientific'      => fn (mixed $v, ?string $locale = null): string
                => $this->scientific($v, $locale),
            'spellout'        => fn (mixed $v, ?string $locale = null): string
                => $this->spellout($v, $locale),
            'ordinal'         => fn (mixed $v, ?string $locale = null): string
                => $this->ordinal($v, $locale),
            'format_date'     => fn (mixed $v, string $style = 'medium', ?string $locale = null, ?string $tz = null): string
                => $this->formatDate($v, $style, $locale, $tz),
            'format_time'     => fn (mixed $v, string $style = 'medium', ?string $locale = null, ?string $tz = null): string
                => $this->formatTime($v, $style, $locale, $tz),
            'format_datetime' => fn (mixed $v, string $ds = 'medium', string $ts = 'medium', ?string $locale = null, ?string $tz = null): string
                => $this->formatDateTime($v, $ds, $ts, $locale, $tz),
            'format_relative' => fn (mixed $v, ?string $locale = null): string
                => $this->formatRelative($v, $locale),
            'transliterate'   => fn (mixed $v, string $rules = 'Any-Latin; Latin-ASCII'): string
                => $this->transliterate($v, $rules),
            'format_message'  => fn (string $pattern, ?array $vars = null, ?string $locale = null): string
                => $this->formatMessage($pattern, $vars, $locale),
        ];

        foreach ($format as $name => $filter) {
            $engine->addFilter($name, $filter);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Reset the locale scope at the end of a request.
     *
     * Called by `AppContext::clearRequestScope()` in a resident worker. The
     * formatter, its caches, and the configuration persist across requests.
     */
    public function resetState(): void
    {
        while ($this->localeService->current() !== null) {
            $this->localeService->pop();
        }

        $this->requestLocale = null;
    }
}
