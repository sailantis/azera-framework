<?php

namespace Azera\Tests\Localization;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Engines\ClarityEngine;
use Azera\Localization\Translator;
use Clarity\Localization\ArrayTranslationLoader;
use Clarity\Localization\LocaleService;
use PHPUnit\Framework\TestCase;

/**
 * Template ↔ controller integration for the Clarity engine.
 *
 * Templates are written to a temporary directory; the compiled cache lives
 * alongside them so a stale file from another test cannot be reused.
 */
class ClarityBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_i18n_' . \bin2hex(\random_bytes(6));
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

    private function translator(): Translator
    {
        return new Translator([
            'locale'          => 'de_DE',
            'fallback_locale' => 'en_US',
            'loader'          => new ArrayTranslationLoader([
                'messages' => [
                    'de_DE' => ['greeting' => 'Hallo {name}', 'only_de' => 'Nur Deutsch'],
                    'en_US' => ['greeting' => 'Hello {name}', 'only_en' => 'English only'],
                ],
                'books' => [
                    'de_DE' => ['title' => 'Bücher'],
                    'en_US' => ['title' => 'Books'],
                ],
            ]),
        ]);
    }

    private function template(string $name, string $source): string
    {
        \file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $name . '.clarity.html',
            $source
        );

        return $name;
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

    // ---- t filter -----------------------------------------------------------

    public function testTemplateUsesTranslatorCatalogue(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template('page', '{{ "greeting" |> t({name: "Anna"}) }}');

        $this->assertSame('Hallo Anna', $engine->renderPartial($view));
    }

    public function testTemplateAndControllerShareOneCatalogue(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template('page', '{{ "only_en" |> t }}');

        // Fallback locale serves the template, exactly as it serves the controller.
        $this->assertSame('English only', $engine->renderPartial($view));
        $this->assertSame('English only', $t->get('only_en'));
    }

    public function testTemplateCanSwitchLocalePerCall(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template('page', '{{ "greeting" |> t({name: "Anna"}, locale: "en_US") }}');

        $this->assertSame('Hello Anna', $engine->renderPartial($view));
    }

    public function testTemplateCanSwitchDomainPerCall(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template('page', '{{ "title" |> t(domain: "books") }}');

        $this->assertSame('Bücher', $engine->renderPartial($view));
    }

    // ---- Blocks -------------------------------------------------------------

    public function testWithT_DomainBlockSwitchesDomain(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template(
            'page',
            '{% with_t_domain "books" %}{{ "title" |> t }}{% endwith_t_domain %}-{{ "title" |> t }}'
        );

        $this->assertSame('Bücher-title', $engine->renderPartial($view));
        $this->assertSame('messages', $t->currentDomain());
    }

    public function testWithLocaleBlockChangesSharedLocale(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template(
            'page',
            '{% with_locale "en_US" %}{{ "greeting" |> t({name: "Anna"}) }}{% endwith_locale %}'
        );

        $this->assertSame('Hello Anna', $engine->renderPartial($view));
        $this->assertSame(
            'Hallo Anna',
            $t->get('greeting', ['name' => 'Anna']),
            'The template block must not leak into later lookups'
        );
    }

    public function testControllerLocaleScopeIsVisibleInTemplate(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->template('page', '{{ "greeting" |> t({name: "Anna"}) }}');

        $rendered = $t->withLocale('en_US', fn (): string => $engine->renderPartial($view));

        $this->assertSame('Hello Anna', $rendered);
    }

    // ---- Shared locale service ---------------------------------------------

    public function testEngineLocaleServiceIsAdopted(): void
    {
        $engine = $this->engine();
        $shared = new LocaleService(['locale' => 'en_US']);
        $engine->addService('locale', $shared);

        $t = $this->translator();
        $t->bind($engine);

        $this->assertSame($shared, $t->localeService());
        // The service's default locale outranks the translator's own only as a
        // last resort, so the translator's `locale` option still wins here.
        $this->assertSame('de_DE', $t->locale());
    }

    public function testTranslatorLocaleServiceIsInstalledOnTheEngine(): void
    {
        $engine = $this->engine();
        $t = $this->translator();
        $t->bind($engine);

        $this->assertTrue($engine->hasService('locale'));
        $this->assertSame($t->localeService(), $engine->getService('locale'));
    }

    public function testBindIsIdempotent(): void
    {
        $engine = $this->engine();
        $t = $this->translator();
        $t->bind($engine);
        $t->bind($engine);

        $view = $this->template('page', '{{ "greeting" |> t({name: "Anna"}) }}');

        $this->assertSame('Hallo Anna', $engine->renderPartial($view));
        $this->assertSame($t, $engine->getService('t'));
    }
}
