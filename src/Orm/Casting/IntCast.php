<?php

namespace Azera\Orm\Casting;

/**
 * Scalar casts: decode the strings stringifying drivers return
 * (pdo_mysql emulated prepares, pdo_pgsql) into the declared PHP type.
 *
 * Applied to BOTH the entity property and the heap snapshot during
 * hydration so diff() compares like with like — without the snapshot
 * coercion, the property coerces (weak-mode typed assignment) while the
 * snapshot keeps the raw string, and the first persist of an UNCHANGED
 * entity schedules a redundant UPDATE per numeric column.
 *
 * Encode is pass-through for all three: PHP already binds ints/floats/
 * bools natively and the server coerces on write.
 *
 * Decode is STRICT: a value that is not a well-formed number is REJECTED
 * rather than silently coerced. `(int) 'abc'` is 0 and `(float) 'abc'`
 * is 0.0, so a corrupted numeric column, a mis-typed value, or a broken
 * driver mapping used to surface as a plausible-looking zero — the worst
 * kind of data bug, because it is indistinguishable from a real 0 and
 * writes back over the original value. Accepted forms are exactly what
 * the three target drivers produce:
 *
 *   int    '42', '-7', '0', 42, 42.0 (integral float), '+3'
 *   float  '4.5', '-0.25', '1e3', '.5', '42', 42
 */

/** @internal registry detail — registered as 'int' */
final class IntCast implements Cast
{
    public function encode(mixed $value): mixed
    {
        return $value;
    }

    public function decode(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (\is_string($value)) {
            $int = (int) $value;

            // C-Level Strict Check: ensure the string represents a perfect integer.
            // Covers "123", "0", "-45", but fails for "123abc", "0.0", "", "abc".
            if ((string) $int === $value) {
                return $int;
            }

            // Whitespace-padded or single-sign-prefixed spellings the drivers
            // and hand-written literals produce: trim, strip EXACTLY ONE
            // sign, then require pure digits. Stripping a sign RUN (ltrim)
            // would let '--42' pass and decode to 0 — the exact silent-zero
            // corruption this cast exists to reject.
            $digits = \trim($value);
            if ($digits !== '' && ($digits[0] === '+' || $digits[0] === '-')) {
                $digits = \substr($digits, 1);
            }
            if (\ctype_digit($digits)) {
                return $int;
            }

            throw new \RuntimeException(
                'Cannot decode ' . var_export($value, true) . ' as int — invalid integer string.'
            );
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            $int = (int) $value;
            if ((float) $int === $value) {
                return $int;
            }

            throw new \RuntimeException('Cannot decode float ' . $value . ' as int — loss of precision.');
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw new \RuntimeException(
            'Cannot decode ' . get_debug_type($value) . ' as int.'
        );
    }
}