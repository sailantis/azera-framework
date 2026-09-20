<?php

namespace Azera\Orm\Casting;

/**
 * Scalar bool cast — see IntCast for the shared rationale.
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
        return $value;
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