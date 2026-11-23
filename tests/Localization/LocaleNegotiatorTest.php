<?php

namespace Azera\Tests\Localization;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Localization\LocaleNegotiator;
use PHPUnit\Framework\TestCase;

class LocaleNegotiatorTest extends TestCase
{
    public function testExactMatchWins(): void
    {
        $this->assertSame('de_DE', LocaleNegotiator::best(['en_US', 'de_DE'], ['de_DE']));
    }

    public function testTagSeparatorIsIndifferent(): void
    {
        $this->assertSame('de_AT', LocaleNegotiator::best(['de_AT', 'en_US'], ['de-at']));
        // The available entry is returned as given, not normalized.
        $this->assertSame('de-AT', LocaleNegotiator::best(['de-AT'], ['de_AT']));
    }

    public function testRegionSpecificRequestFallsBackToSameLanguage(): void
    {
        $this->assertSame('de_DE', LocaleNegotiator::best(['en_US', 'de_DE'], ['de-AT']));
    }

    public function testPlainLanguageSelectsRegionalVariant(): void
    {
        $this->assertSame('de_DE', LocaleNegotiator::best(['en_US', 'de_DE'], ['de']));
    }

    public function testWildcardSelectsFirstAvailable(): void
    {
        $this->assertSame('en_US', LocaleNegotiator::best(['en_US', 'de_DE'], ['*']));
    }

    public function testFirstAcceptedTagWins(): void
    {
        // Request::languages(true) sorts by quality, so list order is priority.
        $this->assertSame(
            'fr_FR',
            LocaleNegotiator::best(['en_US', 'de_DE', 'fr_FR'], ['fr-CA', 'de', 'en'])
        );
    }

    public function testFallbackWhenNothingMatches(): void
    {
        $this->assertSame('en_US', LocaleNegotiator::best(['en_US', 'de_DE'], ['ja_JP'], 'en_US'));
    }

    public function testNoFallbackReturnsNull(): void
    {
        $this->assertNull(LocaleNegotiator::best(['en_US'], ['ja_JP']));
    }

    public function testEmptyAvailableReturnsFallback(): void
    {
        $this->assertSame('en_US', LocaleNegotiator::best([], ['de_DE'], 'en_US'));
    }

    public function testEmptyAcceptedReturnsFallback(): void
    {
        $this->assertSame('en_US', LocaleNegotiator::best(['de_DE'], [], 'en_US'));
    }
}
