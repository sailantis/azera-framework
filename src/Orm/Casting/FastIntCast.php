<?php

namespace Azera\Orm\Casting;

/**
 * Scalar int cast — the FAST, NON-STRICT sibling of {@see IntCast}.
 *
 * Drop-in replacement for applications that prefer raw speed over the
 * strict-decode contract. Register it to override the built-in in a
 * composition root, BEFORE the first Metadata::for() of the affected
 * classes:
 *
 *     Casts::register('int', new FastIntCast());
 *
 * FastHydrator compiles the decode plan per class ONCE, so a later
 * registration additionally needs Metadata::clear() +
 * FastHydrator::clear() for already-compiled classes (see
 * {@see Casts::register()}).
 *
 * Differences from IntCast:
 *
 * - decode() coerces with a bare `(int)` — no form validation. `'abc'`
 *   decodes to 0, `'12.9'` to 12, exactly like pre-strict IntCast.
 * - Roughly 30-55% faster per value (measured 2026-09-19: 55ns vs
 *   86ns for a digit string) at the cost of silent zeros on corrupt
 *   data — the corruption class IntCast exists to reject.
 *
 * Registered by NOTHING by default — the built-in 'int' is the strict
 * IntCast. This class only becomes active through an explicit register().
 *
 * @internal drop-in registry detail — registers as 'int'
 */
final class FastIntCast implements Cast
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

        return (int) $value;
    }
}