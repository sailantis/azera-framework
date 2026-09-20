<?php

namespace Azera\Orm\Casting;

/**
 * Scalar float cast — the FAST, NON-STRICT sibling of {@see FloatCast}.
 *
 * Drop-in replacement for applications that prefer raw speed over the
 * strict-decode contract. Register it to override the built-in in a
 * composition root, BEFORE the first Metadata::for() of the affected
 * classes:
 *
 *     Casts::register('float', new FastFloatCast());
 *
 * FastHydrator compiles the decode plan per class ONCE, so a later
 * registration additionally needs Metadata::clear() +
 * FastHydrator::clear() for already-compiled classes (see
 * {@see Casts::register()}).
 *
 * Differences from FloatCast:
 *
 * - decode() coerces with a bare `(float)` — no form validation.
 *   `'abc'` decodes to 0.0, exactly like pre-strict FloatCast.
 * - Roughly 40-50% faster per value (measured 2026-09-19: 59ns vs
 *   93ns for a decimal string) at the cost of silent zeros on corrupt
 *   data — the corruption class FloatCast exists to reject.
 *
 * Registered by NOTHING by default — the built-in 'float' is the
 * strict FloatCast. This class only becomes active through an
 * explicit register().
 *
 * @internal drop-in registry detail — registers as 'float'
 */
final class FastFloatCast implements Cast
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

        return (float) $value;
    }
}