<?php

namespace Azera\Tests\Localization;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Http\Request;
use Azera\Localization\LoaderInterface;
use Azera\Localization\NullLoader;
use Azera\Localization\Translator;
use Clarity\Localization\ArrayTranslationLoader;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase
{
    private function translator(array $messages = [], array $config = []): Translator
    {
        return new Translator([
            'locale'          => 'de_DE',
            'fallback_locale' => 'en_US',
            'loader'          => new ArrayTranslationLoader($messages),
        ] + $config);
    }

    private function messages(): array
    {
        return [
            'messages' => [
                'de_DE' => [
                    'greeting' => 'Hallo {name}',
                    'nav'      => ['home' => 'Startseite'],
                ],
                'en_US' => [
                    'greeting'   => 'Hello {name}',
                    'only_en'    => 'English only',
                    'nav'        => ['home' => 'Home'],
                ],
            ],
            'books' => [
                'de_DE' => ['title' => 'Bücher'],
                'en_US' => ['title' => 'Books'],
            ],
        ];
    }

    // ---- Lookup -------------------------------------------------------------

    public function testTranslatesWithPlaceholders(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('Hallo Anna', $t->get('greeting', ['name' => 'Anna']));
    }

    public function testNestedKeysAreFlattened(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('Startseite', $t->get('nav.home'));
    }

    public function testMissingKeyEchoes(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('unknown.key', $t->get('unknown.key'));
        $this->assertFalse($t->has('unknown.key'));
    }

    public function testFallsBackToFallbackLocale(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('English only', $t->get('only_en'));
    }

    public function testPerCallLocaleOverridesDefault(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('Hello Anna', $t->get('greeting', ['name' => 'Anna'], null, 'en_US'));
    }

    public function testGetOrDefaultDistinguishesMissingKey(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('Bücher', $t->getOrDefault('title', 'Fallback', null, 'books'));
        $this->assertSame('Fallback', $t->getOrDefault('missing', 'Fallback', null, 'books'));
        $this->assertSame('must be at least 3', $t->getOrDefault('missing', 'must be at least {min}', ['min' => 3]));
    }

    public function testCatalogReturnsWholeDomain(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('Books', $t->catalog('books', 'en_US')['title']);
    }

    // ---- Domains ------------------------------------------------------------

    public function testDomainOverridePerCall(): void
    {
        $t = $this->translator($this->messages());

        $this->assertSame('Bücher', $t->get('title', null, 'books'));
    }

    public function testDomainStackIsNestable(): void
    {
        $t = $this->translator($this->messages());

        $outer = $t->withDomain('books', function () use ($t): array {
            return [
                $t->currentDomain(),
                $t->withDomain('messages', fn (): string => $t->currentDomain()),
                $t->currentDomain(),
            ];
        });

        $this->assertSame(['books', 'messages', 'books'], $outer);
        $this->assertSame('messages', $t->currentDomain());
    }

    public function testDomainScopeIsPoppedOnException(): void
    {
        $t = $this->translator($this->messages());

        try {
            $t->withDomain('books', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('messages', $t->currentDomain());
    }

    // ---- Locale scopes ------------------------------------------------------

    public function testWithLocaleChangesLookupAndRestores(): void
    {
        $t = $this->translator($this->messages());

        $inside = $t->withLocale('en_US', fn (): string => $t->get('greeting', ['name' => 'Anna']));

        $this->assertSame('Hello Anna', $inside);
        $this->assertSame('Hallo Anna', $t->get('greeting', ['name' => 'Anna']));
    }

    public function testLocaleScopeIsPoppedOnException(): void
    {
        $t = $this->translator($this->messages());

        try {
            $t->withLocale('en_US', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($t->currentLocale());
    }

    // ---- Negotiation --------------------------------------------------------

    public function testNegotiateAdoptsAcceptedLanguage(): void
    {
        $t = $this->translator($this->messages());
        $request = new Request(server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);

        $this->assertSame('en_US', $t->negotiate($request, ['de_DE', 'en_US']));
        $this->assertSame('Hello Anna', $t->get('greeting', ['name' => 'Anna']));
    }

    public function testNegotiatedLocaleIsBelowLocaleScope(): void
    {
        $t = $this->translator($this->messages());
        $request = new Request(server: ['HTTP_ACCEPT_LANGUAGE' => 'en_US']);
        $t->negotiate($request, ['de_DE', 'en_US']);

        $this->assertSame(
            'Hallo Anna',
            $t->withLocale('de_DE', fn (): string => $t->get('greeting', ['name' => 'Anna']))
        );
    }

    // ---- Lifecycle ----------------------------------------------------------

    public function testResetStateClearsScopesAndNegotiation(): void
    {
        $t = $this->translator($this->messages());
        $t->pushLocale('en_US');
        $t->pushDomain('books');
        $t->setRequestLocale('en_US');

        $t->resetState();

        $this->assertNull($t->currentLocale());
        $this->assertNull($t->requestLocale());
        $this->assertSame('messages', $t->currentDomain());
        $this->assertSame('Hallo Anna', $t->get('greeting', ['name' => 'Anna']));
    }

    // ---- Loaders ------------------------------------------------------------

    public function testNullLoaderEchoesKeys(): void
    {
        $t = new Translator(['locale' => 'en_US']);

        $this->assertInstanceOf(NullLoader::class, $t->loader());
        $this->assertSame('some.key', $t->get('some.key'));
    }

    public function testClarityLoaderIsBridged(): void
    {
        $t = new Translator(['loader' => new ArrayTranslationLoader([
            'messages' => ['en_US' => ['k' => 'v']],
        ])]);

        $this->assertNotInstanceOf(ArrayTranslationLoader::class, $t->loader());
        $this->assertSame('v', $t->get('k', null, null, 'en_US'));
    }

    public function testAzeraLoaderIsAcceptedDirectly(): void
    {
        $loader = new class implements LoaderInterface {
            public function load(string $domain, string $locale): array
            {
                return ['k' => 'from-azera-loader'];
            }
        };

        $t = new Translator(['loader' => $loader]);

        $this->assertSame($loader, $t->loader());
        $this->assertSame('from-azera-loader', $t->get('k'));
    }

    public function testLoaderArrayShorthand(): void
    {
        $t = new Translator([
            'locale' => 'de_DE',
            'loader' => ['messages' => ['de_DE' => ['greeting' => 'Hallo {name}']]],
        ]);

        $this->assertSame('Hallo Anna', $t->get('greeting', ['name' => 'Anna']));
    }

    public function testConfigureKeepsLoaderWhenNotGiven(): void
    {
        $t = $this->translator($this->messages());
        $t->configure(['locale' => 'en_US']);

        $this->assertSame('Hello Anna', $t->get('greeting', ['name' => 'Anna']));
    }
}
