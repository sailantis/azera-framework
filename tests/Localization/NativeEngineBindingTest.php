<?php

namespace Azera\Tests\Localization;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Core\Engines\NativeEngine;
use Azera\Localization\Translator;
use Clarity\Localization\ArrayTranslationLoader;
use PHPUnit\Framework\TestCase;

/**
 * Translation in plain-PHP templates: the engine gains a `t()` function, which
 * `NativeEngine::__call()` routes from `$this->t(...)` inside an included view.
 */
class NativeEngineBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azera_native_i18n_' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->root . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($this->root);
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

    private function view(string $name, string $source): string
    {
        \file_put_contents($this->root . DIRECTORY_SEPARATOR . $name . '.php', $source);

        return $name;
    }

    private function engine(): NativeEngine
    {
        return (new NativeEngine())->setViewPath($this->root);
    }

    public function testTemplateCallsTranslator(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->view('page', '<?= $this->t("greeting", ["name" => "Anna"]) ?>');

        $this->assertSame('Hallo Anna', $engine->renderPartial($view));
    }

    public function testTemplateSharesTheTranslatorDomain(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->view('page', '<?= $this->t("title", [], "books") ?>');

        $this->assertSame('Bücher', $engine->renderPartial($view));
    }

    public function testTemplateUsesActiveDomainScope(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->view('page', '<?= $this->t("title") ?>');

        $rendered = $t->withDomain('books', fn (): string => $engine->renderPartial($view));

        $this->assertSame('Bücher', $rendered);
        $this->assertSame('messages', $t->currentDomain());
    }

    public function testTemplateUsesLocaleScope(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->view('page', '<?= $this->t("greeting", ["name" => "Anna"]) ?>');

        $this->assertSame(
            'Hello Anna',
            $t->withLocale('en_US', fn (): string => $engine->renderPartial($view))
        );
    }

    public function testUnknownKeyEchoesInTemplate(): void
    {
        $t = $this->translator();
        $engine = $this->engine();
        $t->bind($engine);

        $view = $this->view('page', '<?= $this->t("missing.key") ?>');

        $this->assertSame('missing.key', $engine->renderPartial($view));
    }
}
