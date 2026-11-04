<?php

namespace Azera\Orm\Casting;

/**
 * Scalar bool cast — see IntCast for the shared decode rationale.
 *
 * Encode is NOT pass-through here, unlike the numeric casts. pdo_pgsql sends a
 * bound PHP `false` as the empty string, and PostgreSQL rejects '' for a
 * `boolean` column ("ungültige Eingabesyntax für Typ boolean"). PHP `true`
 * happens to work because it stringifies to '1', so the failure only ever
 * appears on rows that write `false`: a boolean column left at its default
 * breaks the INSERT while the identical row with the box ticked succeeds.
 *
 * Normalising to '1'/'0' makes the wire format driver-independent — both
 * literals are accepted by PostgreSQL and by MySQL.
 *
 * @internal registry detail — registered as 'bool'
 */
final class BoolCast implements Cast
{
    /** Truthy literal store representations (mysql '1', pg 't'). */
    private const TRUTHY = ['1', 't', 'true', 'y', 'yes', 'on'];

    /** Falsy literal store representations (mysql '0', pg 'f'). */
    private const FALSY = ['0', 'f', 'false', 'n', 'no', 'off', ''];

    public function encode(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_int($value)) {
            return $value === 0 ? '0' : '1';
        }

        // Validate rather than cast: (bool) 'false' is true, so a loose cast
        // would silently invert any non-canonical spelling that reaches the
        // write path.
        $s = \strtolower(\trim((string) $value));

        if (\in_array($s, self::TRUTHY, true)) {
            return '1';
        }

        if (\in_array($s, self::FALSY, true)) {
            return '0';
        }

        throw new \RuntimeException(
            "Cannot encode '{$s}' as bool — extend BoolCast or declare the column with a non-bool type"
        );
    }

    public function decode(mixed $value): mixed
    {
        if ($value === null || \is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value !== 0;
        }

        $s = \strtolower(\trim((string) $value));

        if (\in_array($s, self::TRUTHY, true)) {
            return true;
        }

        if (\in_array($s, self::FALSY, true)) {
            return false;
        }

        throw new \RuntimeException(
            "Cannot decode '{$s}' as bool — extend BoolCast or declare the column with a non-bool type"
        );
    }
}
