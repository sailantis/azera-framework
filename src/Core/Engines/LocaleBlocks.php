<?php

namespace Azera\Core\Engines;

use Clarity\ClarityException;
use Clarity\Engine\Directive;
use Clarity\Localization\LocaleService;
use Clarity\Template\TemplateLocation;

/**
 * Installs the shared locale stack on a Clarity-compatible engine, once.
 *
 * Both Azera bridges need the same two things: the engine's `'locale'` service
 * must be the one the framework uses, and `{% with_locale %}` must be declared
 * against it. Doing that in each bridge would declare the block twice — Clarity
 * rejects a duplicate block declaration — so the work lives here and is guarded
 * by a marker service.
 *
 * `LocaleService::registerBlocks()` cannot be used: it is typed against
 * `Clarity\ClarityEngine`, and Azera's engine only *uses* Clarity's trait. The
 * emitted PHP is identical, so a template cannot tell the difference.
 */
final class LocaleBlocks
{
    /** Marker service recording that the block is already declared. */
    private const MARKER = '__azera_locale_blocks';

    /**
     * Ensure `$engine` exposes `$service` as `'locale'` and declares the block.
     *
     * If the engine already has a `'locale'` service, that instance wins and is
     * returned, so whichever bridge registered first owns the shared stack.
     *
     * @return LocaleService|null The shared stack, or null when the engine has
     *                            none and `$service` was null.
     */
    public static function install(object $engine, ?LocaleService $service): ?LocaleService
    {
        if ($engine->hasService('locale')) {
            $installed = $engine->getService('locale');

            return $installed instanceof LocaleService ? $installed : null;
        }

        if ($service === null) {
            return null;
        }

        $engine->addService('locale', $service);
        self::declare($engine);

        return $service;
    }

    /**
     * Declare `{% with_locale %}` / `{% endwith_locale %}`, once per engine.
     */
    public static function declare(object $engine): void
    {
        if ($engine->hasService(self::MARKER)) {
            return;
        }

        $engine->addService(self::MARKER, true);

        $engine->addDirective(
            'with_locale',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                $rest = \trim($rest);
                if ($rest === '') {
                    throw new ClarityException(
                        "'with_locale' requires a locale argument, e.g. {% with_locale \"fr_FR\" %}",
                        $at
                    );
                }
                $param = $processExpr($rest);

                return "\$__c_sv['locale']->push({$param});";
            },
            Directive::opens('endwith_locale')
        );

        $engine->addDirective(
            'endwith_locale',
            static function (string $rest, TemplateLocation $at, callable $processExpr): string {
                return "\$__c_sv['locale']->pop();";
            },
            Directive::closes('with_locale')
        );
    }

    /** Whether the block is already declared on the engine. */
    public static function isDeclared(object $engine): bool
    {
        return $engine->hasService(self::MARKER);
    }
}
