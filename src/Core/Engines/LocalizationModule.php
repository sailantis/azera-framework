<?php

namespace Azera\Core\Engines;

use Azera\Localization\Translator;
use Clarity\ClarityException;
use Clarity\Engine\Directive;
use Clarity\Localization\TranslationLoaderInterface;
use Clarity\Template\TemplateLocation;

/**
 * Binds {@see Translator} to the Clarity template engine.
 *
 * Registers exactly what Clarity's own localization modules register — the `t`
 * filter, the `{% with_t_domain %}` block and the `{% with_locale %}` block — but
 * backed by the framework's translator instead of a Clarity-owned one:
 *
 * ```text
 * {{ "key" |> t }}        →  $__c_sv['t']->get(...)          (Azera\Localization\Translator)
 * {% with_t_domain "x" %} →  $__c_sv['t']->pushDomain("x")
 * {% with_locale "de" %}  →  $__c_sv['locale']->push("de")   (shared LocaleService) * ```
 *
 * One catalogue, one locale stack
 * ------------------------------
 * The translator keeps a single {@see LocaleService}, installed here as the
 * engine's `'locale'` service, so a template `{% with_locale %}` block changes
 * the locale seen by `$translator->get()` — and a controller's
 * `$translator->withLocale()` changes what the template sees. If the engine
 * already exposes a `'locale'` service, that one wins and the translator adopts
 * it, so whichever side installs first, the other reuses its stack.
 *
 * Why this does not reuse `Clarity\Localization\TranslationModule`
 * ---------------------------------------------------------------
 * Clarity types `ModuleInterface::register(ClarityEngine $engine)` and
 * `LocaleService::registerBlocks()` against `Clarity\ClarityEngine`.
 * Azera's {@see ClarityEngine} only *uses* Clarity's trait — it extends the
 * framework's {@see \Azera\Core\ViewEngine} — so passing it to those methods
 * throws a `TypeError`. The trait API (`addInlineFilter`, `addDirective`,
 * `addService`) carries no such constraint, so registration goes through it
 * directly. The registrations mirror Clarity's modules exactly; only the
 * service objects differ.
 *
 * For the same reason this class is deliberately not a Clarity
 * `ModuleInterface` implementation: it must be callable with the Azera engine.
 */
final class LocalizationModule
{
    /**
     * @param Translator $translator The framework translator to back templates with.
     */
    public function __construct(private Translator $translator) {}

    /**
     * Register translation into a Clarity-compatible engine.
     *
     * Accepts `object` because the engine is Azera's {@see ClarityEngine}, which
     * is not a `Clarity\ClarityEngine`. The required methods come from
     * `Clarity\ClarityEngineTrait`, so the capability check is duck-typed.
     *
     * @throws \InvalidArgumentException When the engine lacks the trait API.
     */
    public function registerOn(object $engine): void
    {
        foreach (['addInlineFilter', 'addDirective', 'addService', 'hasService', 'getService'] as $method) {
            if (!\method_exists($engine, $method)) {
                throw new \InvalidArgumentException(
                    'LocalizationModule requires an engine exposing ' . $method . '(); ' . $engine::class . ' does not.'
                );
            }
        }

        $this->installLocaleStack($engine);
        $engine->addService('t', $this->translator);

        // Binding twice is a no-op for the directives: Clarity rejects a
        // duplicate block declaration, and the previous registration already
        // points the compiled code at this translator.
        if ($engine->hasService('__azera_localization')) {
            return;
        }
        $engine->addService('__azera_localization', $this->translator);

        $this->installTranslationFilter($engine);
        $this->installDomainBlocks($engine);
    }

    /**
     * Make the engine and the translator share one locale stack.
     *
     * An already installed `'locale'` service wins — a module registered first,
     * or an app that registered `LocaleService` explicitly — so the translator
     * adopts it rather than replacing it. {@see LocaleBlocks} installs it (and
     * declares `{% with_locale %}`) at most once per engine, so binding both the
     * translator and the intl service cannot declare the block twice.
     */
    private function installLocaleStack(object $engine): void
    {
        $shared = LocaleBlocks::install($engine, $this->translator->localeService());

        if ($shared !== null) {
            $this->translator->useLocaleService($shared);
        }
    }
    /**
     * Register the `t` inline filter.
     *
     * Signature: `t($key, $vars = null, $domain = null, $locale = null)`, so all
     * named-argument forms work:
     *
     * ```twig
     * {{ "key" |> t }}
     * {{ "key" |> t({name: user.name}) }}
     * {{ "key" |> t(domain: "books") }}
     * {{ "key" |> t(locale: "de_DE") }}
     * ```
     */
    private function installTranslationFilter(object $engine): void
    {
        $engine->addInlineFilter('t', [
            'php'      => "\$__c_sv['t']->get({1}, {2}, {3}, {4})",
            'params'   => ['vars', 'domain', 'locale'],
            'defaults' => ['vars' => 'null', 'domain' => 'null', 'locale' => 'null'],
        ]);
    }

    /**
     * Register the `{% with_t_domain %}` / `{% endwith_t_domain %}` block.
     *
     * The compiled code calls `pushDomain()` / `popDomain()` on the translator,
     * which keeps the domain stack in the same object `get()` reads, so a
     * controller's `withDomain()` and a template block cannot disagree.
     */
    private function installDomainBlocks(object $engine): void
    {
        $engine->addDirective(
            'with_t_domain',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                $rest = \trim($rest);
                if ($rest === '') {
                    throw new ClarityException(
                        "'with_t_domain' requires a domain argument, e.g. {% with_t_domain \"emails\" %}",
                        $at
                    );
                }
                $param = $processExpr($rest);

                return "\$__c_sv['t']->pushDomain({$param});";
            },
            Directive::opens('endwith_t_domain')
        );

        $engine->addDirective(
            'endwith_t_domain',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                return "\$__c_sv['t']->popDomain();";
            },
            Directive::closes('with_t_domain')
        );
    }

    /**
     * Return the translator's loader in Clarity's shape.
     *
     * For callers that need to hand the same catalogue to a Clarity component
     * elsewhere: a loader that already implements Clarity's interface is passed
     * through untouched, anything else is wrapped, so nothing is copied.
     */
    public function clarityLoader(): TranslationLoaderInterface
    {
        return $this->translator->clarityLoader();
    }
}
