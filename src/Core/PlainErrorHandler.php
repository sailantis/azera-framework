<?php

namespace Azera\Core;

use Azera\Http\Request;
use Azera\Http\Response;
use Throwable;

/**
 * Default handler for 500 responses.
 *
 * The `log_errors` and `display_errors` ini settings control logging and
 * diagnostic output, following PHP's fatal-error configuration.
 *
 * `log_errors` (default On) — write one line to the error log: the class, message,
 * method, path and throw site. When disabled, this handler does not write an
 * error log entry.
 *
 * `display_errors` (default Off in production, On in development) — return the
 * exception details in the response instead of a plain 500 status. When enabled,
 * the body includes the throwable in a PHP-style format, with HTML values escaped
 * when `html_errors` is enabled.
 *
 * `error_reporting` does not control this handler. It handles throwables passed to
 * it by the Kernel, and logging and response details are controlled by the ini
 * settings above.
 *
 * Applications can register a custom {@see ErrorHandlerInterface} to provide
 * features such as PSR-3 logging, structured context, or redaction.
 */
final class PlainErrorHandler implements ErrorHandlerInterface
{
    public function for(Throwable $error, Request $request): Response
    {
        // Contain logging errors so response handling can continue.
        if (self::settingOn('log_errors')) {
            try {
                error_log(sprintf(
                    'Unhandled %s on %s %s: %s in %s:%d',
                    $error::class,
                    $request->method(),
                    $request->path(),
                    $error->getMessage(),
                    $error->getFile(),
                    $error->getLine(),
                ));
            } catch (Throwable) {
                // Continue building the response if logging fails.
            }
        }

        if (self::settingOn('display_errors')) {
            return self::diagnosticResponse($error);
        }

        return Response::text("500 Internal Server Error\n", 500);
    }

    /**
     * Whether an ini switch is enabled, using PHP's boolean value conventions.
     *
     * `ini_get()` returns a string, or `false` when the directive does not exist.
     * Values have several accepted forms: `"1"`, `"On"`, `"yes"`, `"2"` and
     * `"-1"` mean on; `"0"`, `"Off"`, `"no"`, `"false"`, `""` and an
     * unrecognised word mean off. PHP's behavior follows these rules:
     *
     *   - a recognised boolean word wins, case-insensitively
     *   - otherwise an integer value is literal: nonzero is on
     *   - otherwise an unrecognised word is off
     *
     * A boolean cast treats `"Off"` as true. `filter_var(...,
     * FILTER_VALIDATE_BOOLEAN)` recognizes the boolean words but returns false
     * for `"2"`, which PHP treats as enabled.
     */
    private static function settingOn(string $name): bool
    {
        $value = ini_get($name);
        if ($value === false) {
            return false;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['1', 'on', 'yes', 'true'], true)) {
            return true;
        }

        // Numeric values are interpreted as integers; nonzero values are enabled.
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        // Other values, including "0", "off", "no", and "false", are disabled.
        return false;
    }

    /**
     * Format the throwable as a response resembling PHP's uncaught-fatal output.
     *
     * Diagnostic output includes file paths, the exception message, and trace
     * call sites. Enable `display_errors` in environments where these details
     * are appropriate.
     *
     * Output format follows `html_errors`:
     *
     * - `html_errors=1` escapes diagnostic values and returns an HTML response.
     * - `html_errors=0` returns the diagnostic text as `text/plain`.
     */
    private static function diagnosticResponse(Throwable $error): Response
    {
        $plain = sprintf(
            "Fatal error: Uncaught %s: %s in %s:%d\nStack trace:\n%s\n  thrown in %s on line %d",
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            rtrim($error->getTraceAsString()),
            $error->getFile(),
            $error->getLine(),
        );

        if (!self::settingOn('html_errors')) {
            return Response::text($plain . "\n", 500);
        }

        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $html = sprintf(
            "<br />\n<b>Fatal error</b>:  Uncaught %s: %s in %s:%d\nStack trace:\n%s\n"
                . "  thrown in <b>%s</b> on line <b>%d</b><br />\n",
            $escape($error::class),
            $escape($error->getMessage()),
            $escape($error->getFile()),
            $error->getLine(),
            $escape(rtrim($error->getTraceAsString())),
            $escape($error->getFile()),
            $error->getLine(),
        );

        return Response::html($html, 500);
    }
}
