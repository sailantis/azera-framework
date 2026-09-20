<?php

namespace Azera\Orm\Casting;

use Azera\Orm\Casting\Cast;

/**
 * Scalar float cast — see IntCast for the shared rationale, including the
 * strict-decode policy: `(float) 'abc'` is 0.0, which is indistinguishable
 * from a real 0.0, so a non-numeric value throws instead.
 *
 * ## How each driver returns ZERO (measured 2026-09-19)
 *
 * | driver                      | int col      | float col    |
 * | --------------------------- | ------------ | ------------ |
 * | sqlite                      | `int 0`      | `float 0.0`  |
 * | pgsql (always text)         | `'0'`        | `'0'`        |
 * | mysql, emulated prepares    | `'0'`        | `'0'`        |
 * | mysql, native prepares      | `int 0`      | `float 0.0`  |
 * | mysql, DECIMAL/FLOAT col    | `'0.00'`     | `'0.00'`     |
 *
 * sqlite is never stringified — it hands over real PHP int/float, so the
 * `(float)` coercion is essentially free on the common local path.
 * mysql only returns typed values with native prepares ON mysqlnd AND
 * `PDO::ATTR_EMULATE_PREPARES => false`; the pdo_mysql DEFAULT is
 * emulated, and the framework does not override it, so in practice mysql
 * arrives as strings just like pgsql.
 *
 * The `$result !== 0.0` shortcut sends every NON-zero value straight back
 * (one comparison, the common case) and lets ZERO fall through to the
 * form-validating chain below. That is what makes a zero-literal
 * allowlist unnecessary: `'0'`, `'0.00'`, `'0e0'`, `'.0'`, `'0.'` and
 * `' 0 '` are all reachable depending on driver and column type, and
 * is_numeric() covers every spelling at once. The `'0.0'` / `0.0`
 * comparisons are not the validation — the chain below is.
 *
 * @internal registry detail — registered as 'float'
 */
final class FloatCast implements Cast
{
    public function encode(mixed $value): mixed
    {
        return $value;
    }

    public function decode(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (\is_string($value)) {
            // is_numeric() is a fast C-level check on the string pointer:
            // Quickly accepts '0', '0.00', '1e3', '.5', '+2.', '-12.34'
            // Safely blocks '123.45abc', 'abc', ''
            if (\is_numeric($value)) {
                return (float) $value;
            }

            throw new \RuntimeException(
                'Cannot decode ' . var_export($value, true) . ' as float — not a well-formed number.'
            );
        }

        if (\is_float($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (float) $value;
        }

        if (\is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        throw new \RuntimeException(
            'Cannot decode ' . get_debug_type($value) . ' as float — unsupported type.'
        );
    }
}