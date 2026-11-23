<?php

namespace Azera\Core\Engines;

use Azera\Localization\Intl;

/**
 * Binds {@see Intl} to the Clarity template engine.
 *
 * Registers Clarity's `format_*` filters, `locale_*` functions and the
 * `{% with_locale %}` block, all delegating to the framework's formatting
 * service instead of a Clarity-owned formatter:
 *
 * ```text
 * {{ 1234.5 |> format_currency("EUR") }}  →  $__c_fn['format_currency'](…)  →  Azera\Localization\Intl
 * {% with_locale "de_DE" %}               →  $__c_sv['locale']->push("de_DE")
 * ```
 *
 * The filter list mirrors `Clarity\Localization\IntlFormatModule` exactly; only
 * the object behind them differs, which is what keeps a controller's
 * `$ctx->intl()->formatCurrency()` and a template's `|> format_currency`
 * identical.
 *
 * Why this does not reuse `IntlFormatModule`
 * -----------------------------------------
 * `ModuleInterface::register()` and `LocaleService::registerBlocks()` are typed
 * against `Clarity\ClarityEngine`. {@see ClarityEngine} only *uses* Clarity's
 * trait — it extends the framework's {@see \Azera\Core\ViewEngine} — so passing
 * it to either method throws a `TypeError`. The trait API (`addFilter`,
 * `addFunction`, `addDirective`, `addService`) carries no such constraint, so
 * registration goes through it directly.
 */
final class IntlModule
{
    /**
     * @param Intl $intl The formatting service to back templates with.
     */
    public function __construct(private Intl $intl) {}

    /**
     * Register formatting into a Clarity-compatible engine.
     *
     * Accepts `object` because the engine is Azera's {@see ClarityEngine}, which
     * is not a `Clarity\ClarityEngine`. The required methods come from
     * `Clarity\ClarityEngineTrait`, so the capability check is duck-typed.
     *
     * @throws \InvalidArgumentException When the engine lacks the trait API.
     */
    public function registerOn(object $engine): void
    {
        foreach (['addFilter', 'addFunction', 'addDirective', 'addService', 'hasService', 'getService'] as $method) {
            if (!\method_exists($engine, $method)) {
                throw new \InvalidArgumentException(
                    'IntlModule requires an engine exposing ' . $method . '(); ' . $engine::class . ' does not.'
                );
            }
        }

        $this->installLocaleStack($engine);

        // Binding twice is a no-op for the filters and the block: the existing
        // registration already delegates to this service. Clarity rejects a
        // duplicate block declaration, so the marker short-circuits the second pass.
        if ($engine->hasService('__azera_intl')) {
            return;
        }
        $engine->addService('__azera_intl', $this->intl);

        $this->registerNumberFilters($engine);
        $this->registerDateFilters($engine);
        $this->registerLocaleInfoFunctions($engine);
        $this->registerTextFilters($engine);
        $this->registerMessageFilter($engine);
    }

    /**
     * Make the engine and the service share one locale stack.
     *
     * An already installed `'locale'` service wins, so formatting, translation and
     * `{% with_locale %}` all move together. {@see LocaleBlocks} installs it (and
     * declares the block) at most once per engine, so binding both the translator
     * and this service cannot declare the block twice.
     */
    private function installLocaleStack(object $engine): void
    {
        $shared = LocaleBlocks::install($engine, $this->intl->localeService());

        if ($shared !== null) {
            $this->intl->useLocaleService($shared);
        }
    }

    private function registerNumberFilters(object $engine): void
    {
        $intl = $this->intl;

        $engine->addFilter('format_number', fn (mixed $v, int $decimals = 2, ?string $locale = null): string
            => $intl->formatNumber($v, $decimals, $locale));

        $engine->addFilter('format_currency', fn (mixed $v, string $currency = 'EUR', ?string $locale = null): string
            => $intl->formatCurrency($v, $currency, $locale));

        $engine->addFilter('currency_name', fn (string $code, ?string $locale = null): string
            => $intl->currencyName($code, $locale));

        $engine->addFilter('currency_symbol', fn (string $code, ?string $locale = null): string
            => $intl->currencySymbol($code, $locale));

        $engine->addFilter('percent', fn (mixed $v, int $decimals = 0, ?string $locale = null): string
            => $intl->percent($v, $decimals, $locale));

        $engine->addFilter('scientific', fn (mixed $v, ?string $locale = null): string
            => $intl->scientific($v, $locale));

        $engine->addFilter('spellout', fn (mixed $v, ?string $locale = null): string
            => $intl->spellout($v, $locale));

        $engine->addFilter('ordinal', fn (mixed $v, ?string $locale = null): string
            => $intl->ordinal($v, $locale));
    }

    private function registerDateFilters(object $engine): void
    {
        $intl = $this->intl;

        $engine->addFilter('format_date', fn (mixed $v, string $style = 'medium', ?string $locale = null, ?string $tz = null): string
            => $intl->formatDate($v, $style, $locale, $tz));

        $engine->addFilter('format_time', fn (mixed $v, string $style = 'medium', ?string $locale = null, ?string $tz = null): string
            => $intl->formatTime($v, $style, $locale, $tz));

        $engine->addFilter('format_datetime', fn (mixed $v, string $dateStyle = 'medium', string $timeStyle = 'medium', ?string $locale = null, ?string $tz = null): string
            => $intl->formatDateTime($v, $dateStyle, $timeStyle, $locale, $tz));

        $engine->addFilter('format_relative', fn (mixed $v, ?string $locale = null): string
            => $intl->formatRelative($v, $locale));
    }

    private function registerLocaleInfoFunctions(object $engine): void
    {
        $intl = $this->intl;

        $engine->addFunction('country_name', fn (string $code, ?string $displayLocale = null, ?string $locale = null): string
            => $intl->countryName($code, $displayLocale, $locale));

        $engine->addFunction('language_name', fn (string $code, ?string $displayLocale = null, ?string $locale = null): string
            => $intl->languageName($code, $displayLocale, $locale));

        $engine->addFunction('locale_name', fn (string $id, ?string $displayLocale = null, ?string $locale = null): string
            => $intl->localeName($id, $displayLocale, $locale));

        $engine->addFunction('timezone_name', fn (string $tz, ?string $displayLocale = null): string
            => $intl->timezoneName($tz, $displayLocale));
    }

    private function registerTextFilters(object $engine): void
    {
        $engine->addFilter('transliterate', fn (mixed $v, string $rules = 'Any-Latin; Latin-ASCII'): string
            => $this->intl->transliterate($v, $rules));
    }

    private function registerMessageFilter(object $engine): void
    {
        $intl = $this->intl;

        $engine->addFilter('format_message', fn (string $pattern, ?array $vars = null, ?string $locale = null): string
            => $intl->formatMessage($pattern, $vars, $locale));
    }
}
