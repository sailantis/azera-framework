<?php

namespace Azera\Tests\Localization;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Request;
use Azera\Localization\Intl;
use Azera\Localization\Translator;
use Clarity\Localization\ArrayTranslationLoader;
use PHPUnit\Framework\TestCase;

/**
 * The framework formatting service: no view engine, no rendering.
 *
 * The formatting logic itself is tested in clarity-engine (IntlFormatterTest);
 * these cases pin the framework layer — configuration, the shared locale stack,
 * and request-scope reset.
 */
class IntlTest extends TestCase
{
    private function translator(): Translator
    {
        return new Translator([
            'locale' => 'de_DE',
            'loader' => new ArrayTranslationLoader([
                'messages' => ['de_DE' => ['k' => 'Hallo'], 'en_US' => ['k' => 'Hello']],
            ]),
        ]);
    }

    private function intl(): Intl
    {
        return (new Intl(['timezone' => 'Europe/Berlin']))->useTranslator($this->translator());
    }

    // ---- Formatting ---------------------------------------------------------

    public function testFormatsNumbersAndCurrency(): void
    {
        $intl = $this->intl();

        $this->assertSame('1.234,50', $intl->formatNumber(1234.5, 2));
        $this->assertStringContainsString('1.234,50', $intl->formatCurrency(1234.5, 'EUR'));
    }

    public function testFormatsDates(): void
    {
        $intl = $this->intl();

        $this->assertSame('14. November 2023', $intl->formatDate(1700000000, 'long'));
        $this->assertNotSame('', $intl->formatTime(1700000000, 'short'));
        $this->assertNotSame('', $intl->formatDateTime(1700000000, 'short', 'short'));
        $this->assertNotSame('', $intl->formatRelative(time() - 3600));
    }

    public function testFormatsMessages(): void
    {
        $this->assertSame(
            '3 Artikel',
            $this->intl()->formatMessage('{count, plural, one{# Artikel} other{# Artikel}}', ['count' => 3])
        );
    }

    public function testPerCallLocaleOverridesTheActiveOne(): void
    {
        $intl = $this->intl();

        $this->assertSame('1,234.50', $intl->formatNumber(1234.5, 2, 'en_US'));
        $this->assertSame('1.234,50', $intl->formatNumber(1234.5, 2));
    }

    public function testLocaleInformation(): void
    {
        $intl = $this->intl();

        if (!$intl->hasIntl()) {
            $this->assertSame('DE', $intl->countryName('DE'));
            return;
        }

        $this->assertSame('Deutschland', $intl->countryName('DE'));
        $this->assertSame('Deutsch', $intl->languageName('de'));
    }

    // ---- Shared locale stack ------------------------------------------------

    public function testUseTranslatorSharesItsLocaleService(): void
    {
        $translator = $this->translator();
        $intl       = (new Intl())->useTranslator($translator);

        $this->assertSame($translator->localeService(), $intl->localeService());
    }

    public function testLocaleScopeMovesFormattingAndTranslationTogether(): void
    {
        $translator = $this->translator();
        $intl       = (new Intl())->useTranslator($translator);

        $this->assertSame('Hallo', $translator->get('k'));

        $translator->withLocale('en_US', function () use ($translator, $intl): void {
            $this->assertSame('Hello', $translator->get('k'));
            $this->assertSame('1,234.50', $intl->formatNumber(1234.5, 2));
        });

        $this->assertSame('Hallo', $translator->get('k'));
        $this->assertSame('1.234,50', $intl->formatNumber(1234.5, 2));
    }

    public function testScopeThroughIntlIsVisibleToTheTranslator(): void
    {
        $translator = $this->translator();
        $intl       = (new Intl())->useTranslator($translator);

        $translated = $intl->withLocale('en_US', fn (): string => $translator->get('k'));

        $this->assertSame('Hello', $translated);
        $this->assertSame('Hallo', $translator->get('k'));
    }

    public function testScopeIsPoppedOnException(): void
    {
        $intl = $this->intl();

        try {
            $intl->withLocale('en_US', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($intl->currentLocale());
        $this->assertSame('de_DE', $intl->locale());
    }

    public function testUseLocaleServiceAdoptsAnExistingStack(): void
    {
        $translator = $this->translator();
        $intl       = new Intl();

        $intl->useLocaleService($translator->localeService());

        $this->assertSame($translator->localeService(), $intl->localeService());
        $this->assertSame('de_DE', $intl->locale());
    }

    // ---- Configuration ------------------------------------------------------

    public function testConfigureKeepsExistingValues(): void
    {
        $intl = new Intl(['locale' => 'fr_FR']);
        $intl->configure(['timezone' => 'Asia/Tokyo']);

        $this->assertSame('fr_FR', $intl->locale());
    }

    public function testConfigureCanReplaceTheLocaleService(): void
    {
        $translator = $this->translator();
        $intl       = new Intl();

        $intl->configure(['locale_service' => $translator->localeService()]);

        $this->assertSame($translator->localeService(), $intl->localeService());
    }

    // ---- Negotiation --------------------------------------------------------

    public function testNegotiateAdoptsTheBestLocale(): void
    {
        $intl    = $this->intl();
        $request = new Request(server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        $this->assertSame('fr_FR', $intl->negotiate($request, ['en_US', 'de_DE', 'fr_FR']));
    }

    // ---- Lifecycle ----------------------------------------------------------

    public function testResetStateClearsTheLocaleScope(): void
    {
        $intl = $this->intl();
        $intl->pushLocale('en_US');

        $intl->resetState();

        $this->assertNull($intl->currentLocale());
        $this->assertSame('de_DE', $intl->locale());
    }

    public function testFormatterIsExposed(): void
    {
        $intl = $this->intl();

        $this->assertSame($intl->localeService(), $intl->formatter()->localeService());
    }
}
