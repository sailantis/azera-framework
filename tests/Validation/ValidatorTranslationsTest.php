<?php

namespace Azera\Tests\Validation;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Localization\Translator;
use Azera\Validation\Validator;
use Clarity\Localization\ArrayTranslationLoader;
use PHPUnit\Framework\TestCase;

/**
 * `Validator::setTranslations()` — error codes double as catalogue keys, and the
 * English template stays the fallback for codes a catalogue does not cover.
 */
class ValidatorTranslationsTest extends TestCase
{
    private function translator(): Translator
    {
        return new Translator([
            'locale'          => 'de_DE',
            'fallback_locale' => 'en_US',
            'loader'          => new ArrayTranslationLoader([
                'validation' => [
                    'de_DE' => [
                        'required'   => 'Pflichtfeld',
                        'email'      => 'muss eine gültige E-Mail-Adresse sein',
                        'min.string' => 'muss mindestens {min} Zeichen haben',
                        'in'         => 'muss einer der folgenden Werte sein: {allowed}',
                    ],
                ],
            ]),
        ]);
    }

    public function testRequiredIsTranslated(): void
    {
        $v = new Validator([]);
        $v->field('name')->required()->string();
        $v->setTranslations($this->translator());

        $this->assertSame('Pflichtfeld', $v->errors()['name']);
    }

    public function testEmailIsTranslated(): void
    {
        $v = new Validator(['email' => 'nope']);
        $v->field('email')->email();
        $v->setTranslations($this->translator());

        $this->assertSame('muss eine gültige E-Mail-Adresse sein', $v->errors()['email']);
    }

    public function testPlaceholdersAreFilledFromParams(): void
    {
        $v = new Validator(['name' => 'Al']);
        $v->field('name')->string()->min(3);
        $v->setTranslations($this->translator());

        $this->assertSame('muss mindestens 3 Zeichen haben', $v->errors()['name']);
    }

    public function testArrayParamIsRendered(): void
    {
        $v = new Validator(['role' => 'superuser']);
        $v->field('role')->in(['admin', 'editor']);
        $v->setTranslations($this->translator());

        $this->assertSame(
            'muss einer der folgenden Werte sein: admin, editor',
            $v->errors()['role']
        );
    }

    public function testUnknownCodeFallsBackToEnglishTemplate(): void
    {
        $v = new Validator(['ip' => 'nope']);
        $v->field('ip')->ip();
        $v->setTranslations($this->translator());

        $this->assertSame('must be a valid IP address', $v->errors()['ip']);
    }

    public function testCustomDomainIsUsed(): void
    {
        $translator = new Translator([
            'locale' => 'de_DE',
            'loader' => new ArrayTranslationLoader([
                'signup' => ['de_DE' => ['required' => 'Bitte ausfüllen']],
            ]),
        ]);

        $v = new Validator([]);
        $v->field('name')->required()->string();
        $v->setTranslations($translator, 'signup');

        $this->assertSame('Bitte ausfüllen', $v->errors()['name']);
    }

    public function testFollowsActiveLocaleScope(): void
    {
        $translator = $this->translator();

        $v = new Validator([]);
        $v->field('name')->required()->string();
        $v->setTranslations($translator);

        $this->assertSame(
            'required',
            $translator->withLocale('en_US', fn (): string => $v->errors()['name'])
        );
    }

    public function testSetTranslatorStillWinsWhenRegisteredLast(): void
    {
        $v = new Validator([]);
        $v->field('name')->required()->string();
        $v->setTranslations($this->translator());

        $v->setTranslator(static fn ($field, $code, $params, $template): string => 'overridden');

        $this->assertSame('overridden', $v->errors()['name']);
    }
}
