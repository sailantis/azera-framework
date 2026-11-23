<?php

namespace Azera\Tests\Localization;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Engines\ClarityEngine;
use Azera\Localization\Intl;
use Azera\Localization\Translator;
use Clarity\Localization\ArrayTranslationLoader;
use PHPUnit\Framework\TestCase;

/**
 * Template ↔ controller parity for formatting on the Clarity engine.
 *
 * The point of these cases: a controller's `$intl->formatCurrency()` and a
 * template's `|> format_currency` must produce the same string, and binding both
 * the translator and the intl service must not declare `{% with_locale %}`
 * twice.
 */
class IntlClarityBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_intl_bind_' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . DIRECTORY_SEPARATOR . 'views', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function engine(): ClarityEngine
    {
        return (new ClarityEngine())
            ->setViewPath($this->root . DIRECTORY_SEPARATOR . 'views')
            ->setCachePath($this->root . DIRECTORY_SEPARATOR . 'cache');
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            \is_dir($path) ? $this->removeDirectory($path) : @\unlink($path);
        }

        @\rmdir($dir);
    }

    private function template(string $name, string $source): string
    {
        \file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $name . '.clarity.html',
            $source
        );

        return $name;
    }

    private function translator(): Translator
    {
        return new Translator([
            'locale' => 'de_DE',
            'loader' => new ArrayTranslationLoader([
                'messages' => ['de_DE' => ['k' => 'Hallo'], 'en_US' => ['k' => 'Hello']],
            ]),
        ]);
    }

    // ---- Filters ------------------------------------------------------------

    public function testTemplateMatchesDirectCall(): void
    {
        $intl   = new Intl(['locale' => 'de_DE', 'timezone' => 'Europe/Berlin']);
        $engine = $this->engine();
        $intl->bind($engine);

        $view = $this->template('page', '{{ 1234.5 |> format_currency("EUR") }}');

        $this->assertSame($intl->formatCurrency(1234.5, 'EUR'), $engine->renderPartial($view));
    }

    public function testDateTimeAndMessageFiltersRender(): void
    {
        $intl   = new Intl(['locale' => 'en_US', 'timezone' => 'Europe/Berlin']);
        $engine = $this->engine();
        $intl->bind($engine);

        $view = $this->template(
            'page',
            '{{ 1700000000 |> format_date("long") }}|'
            . '{{ 0.25 |> percent }}|'
            . '{{ "{count, plural, one{# item} other{# items}}" |> format_message({count: 3}) }}'
        );

        $this->assertSame(
            $intl->formatDate(1700000000, 'long') . '|25%|3 items',
            $engine->renderPartial($view)
        );
    }

    public function testLocaleInformationFunctionsRender(): void
    {
        $intl   = new Intl(['locale' => 'en_US']);
        $engine = $this->engine();
        $intl->bind($engine);

        $view = $this->template('page', '{{ country_name("DE") }}');

        $this->assertSame($intl->countryName('DE'), $engine->renderPartial($view));
    }

    public function testPerCallLocaleArgumentIsHonoured(): void
    {
        $intl   = new Intl(['locale' => 'de_DE']);
        $engine = $this->engine();
        $intl->bind($engine);

        $view = $this->template('page', '{{ 1234.5 |> format_number(2, "en_US") }}');

        $this->assertSame($intl->formatNumber(1234.5, 2, 'en_US'), $engine->renderPartial($view));
    }

    // ---- Shared stack, both bindings ----------------------------------------

    public function testBindingTranslatorAndIntlDeclaresTheBlockOnce(): void
    {
        $translator = $this->translator();
        $intl       = (new Intl())->useTranslator($translator);
        $engine     = $this->engine();

        $translator->bind($engine);
        $intl->bind($engine);

        $view = $this->template(
            'page',
            '{% with_locale "en_US" %}{{ "k" |> t }}/{{ 1234.5 |> format_currency("EUR") }}{% endwith_locale %}'
        );

        $this->assertSame(
            'Hello/€1,234.50',
            $engine->renderPartial($view)
        );
    }

    public function testIntlFirstThenTranslator(): void
    {
        $translator = $this->translator();
        $intl       = (new Intl())->useTranslator($translator);
        $engine     = $this->engine();

        $intl->bind($engine);
        $translator->bind($engine);

        $this->assertSame($translator->localeService(), $engine->getService('locale'));

        $view = $this->template('page', '{{ "k" |> t }}|{{ 1234.5 |> format_currency("EUR") }}');

        $this->assertSame(
            'Hallo|' . $intl->formatCurrency(1234.5, 'EUR'),
            $engine->renderPartial($view)
        );
    }

    public function testTemplateLocaleBlockAppliesToFormatting(): void
    {
        $intl   = new Intl(['locale' => 'de_DE']);
        $engine = $this->engine();
        $intl->bind($engine);

        $view = $this->template(
            'page',
            '{{ 1234.5 |> format_number(2) }}|'
            . '{% with_locale "en_US" %}{{ 1234.5 |> format_number(2) }}{% endwith_locale %}'
        );

        $rendered = $engine->renderPartial($view);

        $this->assertSame(
            $intl->formatNumber(1234.5, 2) . '|' . $intl->formatNumber(1234.5, 2, 'en_US'),
            $rendered
        );
        // The block must not leak into the next lookup.
        $this->assertNull($intl->currentLocale());
    }

    public function testBindIsIdempotent(): void
    {
        $intl   = new Intl(['locale' => 'de_DE']);
        $engine = $this->engine();

        $intl->bind($engine);
        $intl->bind($engine);

        $view = $this->template('page', '{{ 1234.5 |> format_currency("EUR") }}');

        $this->assertSame($intl->formatCurrency(1234.5, 'EUR'), $engine->renderPartial($view));
    }

    public function testFormatterAdoptsAnEngineLocaleService(): void
    {
        $engine = $this->engine();
        $shared = new \Clarity\Localization\LocaleService(['locale' => 'fr_FR']);
        $engine->addService('locale', $shared);

        $intl = new Intl();
        $intl->bind($engine);

        $this->assertSame($shared, $intl->localeService());
    }
}
