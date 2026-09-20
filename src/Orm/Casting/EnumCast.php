<?php
declare(strict_types=1);

namespace Azera\Orm\Casting;

/**
 * Backed-enum cast: enum cases <-> their scalar backing values.
 *
 * The cast key IS the enum class-string (see {@see Casts}): metadata
 * records `columns[].type = MyEnum::class` — inferred from the property
 * type, or declared with #[Column(type: MyEnum::class)] — and the
 * registry derives the matching instance lazily via
 * {@see EnumCast::for()}. There is therefore no registration step for an
 * application to remember, and nothing to replay after the L2 metadata
 * cache warms (compile() is skipped on a cache hit, so a cast
 * registered as a compile side effect would silently vanish).
 *
 * Contract:
 * - encode: a case -> its `->value`; a backing scalar -> the canonical
 *   `->value`; null -> null. Lenient on scalars so the id backfill and
 *   `cast: false` partial-data paths keep working.
 * - decode: a backing scalar (including the driver's STRING form of an
 *   int backing) -> the case; a case passes through.
 * - Anything else THROWS in BOTH directions. A corrupted or mis-typed
 *   column must stay loud rather than decode to null, which would then
 *   be written back over the original value.
 *
 * The snapshot contract ({@see \Azera\Orm\FastHydrator::put()}) is why
 * encode() and decode() must be inverse: the property holds the CASE
 * while `node->data` holds the scalar, so an unchanged hydrated entity
 * diffs clean and emits no UPDATE.
 *
 * The backing type is resolved ONCE in the constructor because
 * `from()`/`tryFrom()` follow the typing rules of the CALL site: under
 * strict_types an int-backed enum called with the string '2' raises a
 * TypeError instead of matching, so drivers that return numerics as
 * strings (pdo_pgsql, emulated-prepare MySQL) must be normalized HERE
 * and never handed to tryFrom() raw.
 *
 * Instances are memoized per enum class, so one instance is shared by
 * every class and row using that enum.
 *
 * @template T of \BackedEnum
 */
final class EnumCast implements Cast
{
    /** @var array<class-string<\BackedEnum>, self> */
    private static array $instances = [];

    /** 'int'|'string' — the enum's backing type, resolved once. */
    private readonly string $backing;

    /**
     * @param class-string<T> $enumClass
     */
    public function __construct(
        private string $enumClass
    ) {
        // enum_exists() FIRST: ReflectionEnum on a non-enum throws a bare
        // Error, which would bury the real mistake.
        if (!\enum_exists($enumClass) || !\is_subclass_of($enumClass, \BackedEnum::class)) {
            throw new \InvalidArgumentException(
                \sprintf('%s is not a backed enum.', $enumClass)
            );
        }

        // A backed enum's backing type is always a NAMED type; the guard
        // narrows the declared ?ReflectionType so the name is reachable.
        $backing = (new \ReflectionEnum($enumClass))->getBackingType();

        if (!$backing instanceof \ReflectionNamedType) {
            throw new \InvalidArgumentException(
                \sprintf('%s is not a backed enum.', $enumClass)
            );
        }

        $this->backing = $backing->getName();
    }

    /**
     * Memoized instance for an enum class — the registry entry for an
     * enum class-string column type.
     *
     * Instances live for the PROCESS, like the built-in casts: they are
     * immutable (backing type resolved once) and stateless, so sharing
     * one across registry clears, classes and rows is safe. Replacing an
     * enum's cast is done through {@see Casts::register()}, not here.
     *
     * @param class-string<T> $enumClass
     */
    public static function for(string $enumClass): self
    {
        return self::$instances[$enumClass] ??= new self($enumClass);
    }

    public function encode(mixed $value): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->enumClass) {
            return $value->value;
        }

        $scalar = $this->coerce($value);

        if ($scalar !== null) {
            $enum = $this->enumClass::tryFrom($scalar);
            if ($enum !== null) {
                return $enum->value;
            }
        }

        throw new \InvalidArgumentException(
            \sprintf('Cannot encode value of type %s for enum %s.', \get_debug_type($value), $this->enumClass)
        );
    }

    public function decode(mixed $value): ?\BackedEnum
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->enumClass) {
            return $value;
        }

        $scalar = $this->coerce($value);

        if ($scalar !== null) {
            $enum = $this->enumClass::tryFrom($scalar);
            if ($enum !== null) {
                return $enum;
            }
        }

        throw new \RuntimeException(
            \sprintf('Cannot decode %s to Enum %s — invalid backing value.', \var_export($value, true), $this->enumClass)
        );
    }

    /**
     * A raw store/driver value -> the enum's backing scalar, or null when
     * it cannot be one. The ONE normalisation point shared by both
     * directions: an int-backed enum accepts ints and digit strings (the
     * shape pdo_pgsql and emulated-prepare MySQL return for numerics), a
     * string-backed enum accepts strings only. Floats, bools and objects
     * are never a backed enum's scalar, and fall through to the caller's
     * throw.
     */
    private function coerce(mixed $value): int|string|null
    {
        if ($this->backing === 'int') {
            if (\is_int($value)) {
                return $value;
            }

            if (\is_string($value) && \ctype_digit(\ltrim($value, '+-'))) {
                return (int) $value;
            }

            return null;
        }

        return \is_string($value) ? $value : null;
    }
}