<?php

namespace Azera\Orm\Casting;

/**
 * Registry mapping metadata column types to {@see Cast} transformations.
 *
 * The cast key is the COLUMN type declared (or inferred) in metadata —
 * `#[Column(type: 'json')]` or the property-type inference (array ->
 * 'json', int -> 'int', ...). Inference only guesses the PORTABLE default:
 * an `array` property on PostgreSQL backed by a native array column must
 * be declared explicitly as `#[Column(type: 'pgarray')]`.
 *
 * Registered built-ins (registered in {@see Casts::boot()}):
 *
 *   'int'      decode coerces strings -> int (both property AND snapshot)
 *   'float'    decode coerces strings -> float (both directions as int)
 *   'bool'     decode coerces '1'/'0'/'t'/'f'/... -> bool
 *   'json'     encode json_encode, decode json_decode(..., true)
 *   'pgarray'  PostgreSQL native array literal <-> 1-D scalar PHP array
 *   'datetime' DateTimeInterface <-> 'Y-m-d H:i:s' (decode yields
 *              DateTimeImmutable; replace the registration for a
 *              custom shape)
 *
 * ENUM CLASS-STRINGS are keys too, with no registration step: metadata
 * records `columns[].type = MyEnum::class` (inferred from the property
 * type, or declared via #[Column(type: MyEnum::class)]) and the lookup
 * derives the {@see EnumCast} lazily. Deriving at LOOKUP time — rather
 * than registering during the metadata compile — is deliberate: compile()
 * runs only on a cache MISS, so a registration performed as a compile
 * side effect would silently disappear as soon as the L2 metadata cache
 * was warm, and the enum would start binding its case object to PDO.
 *
 * Semantics:
 *
 * - Registered casts apply on BOTH read and write paths; scalar casts
 *   exist because stringifying drivers (pdo_mysql with emulated prepares,
 *   pdo_pgsql) return numerics as strings — without them the typed
 *   property would coerce `int(5)` while the heap snapshot kept `"5"`,
 *   making diff() schedule a redundant UPDATE for every unchanged numeric
 *   column on the first persist after hydration.
 *
 * - Applications can register additional types (encrypted columns, enums,
 *   money, ...): `Casts::register('encrypted', new EncryptedCast())`.
 *   Registration before the first Metadata::for() call of the class, or
 *   Metadata::clear() afterwards — FastHydrator compiles the decode plan
 *   per class once.
 */
final class Casts
{
    /** @var array<string, Cast> */
    private static array $casts = [];

    /**
     * Memoized DERIVED lookups: type => Cast, or null when the type is
     * not an enum class (a negative hit — `array_key_exists` must be
     * used to consult this, since a stored null is meaningful). Keeps
     * the per-lookup `enum_exists()` autoloader probe off the hot path,
     * because `for()` is called once per column by both extractData()
     * and hydration.
     *
     * @var array<string, Cast|null>
     */
    private static array $resolved = [];

    /** @var bool built-ins registered? */
    private static bool $booted = false;

    /**
     * Register (or replace) a cast for a column type.
     */
    public static function register(string $type, Cast $cast): void
    {
        self::boot();

        self::$casts[$type] = $cast;

        // A prior lookup may have memoized this type as cast-free (or as
        // a derived enum cast) — the explicit registration wins now.
        unset(self::$resolved[$type]);
    }

    /**
     * The cast for a column type, or null when the type has no
     * transformation (values pass through raw in both directions).
     *
     * Resolution order: an explicit registration, then the memoized
     * derived answer, then — for a BACKED ENUM class-string — the cast
     * derived from the type itself. Everything else is cast-free.
     */
    public static function for(string $type): ?Cast
    {
        self::boot();

        return self::$casts[$type]
            ?? self::$resolved[$type] ??= self::derive($type);
    }

    /**
     * Derive a cast from the type name itself. Only backed-enum
     * class-strings derive: metadata stores the enum CLASS as the column
     * type, so the enum instance is reachable from the key alone.
     * Pure enums derive nothing here — Metadata rejects them at compile
     * time, because they have no scalar representation to round-trip.
     */
    private static function derive(string $type): ?Cast
    {
        if ($type !== '' && \enum_exists($type) && \is_subclass_of($type, \BackedEnum::class)) {
            return EnumCast::for($type);
        }

        return null;
    }

    /**
     * The ONE cast-resolution choke point: registry lookup GATED by the
     * per-column policy flag resolved at compile time
     * ({@see \Azera\Orm\Metadata} 'columns[].cast'). Null = no cast
     * applies — either the type has none registered, or the column's
     * resolved policy says suppress (mongo's castExclusions, or an
     * explicit #[Column(cast: false)]). Every write AND read site goes
     * through this, so encode/decode always agree on what is shaped.
     *
     * @param array<string, mixed> $col the metadata column entry
     */
    public static function forColumn(array $col): ?Cast
    {
        if (($col['cast'] ?? true) === false) {
            return null;
        }

        return self::for($col['type']);
    }

    /**
     * Registered type names (tests). DERIVED enum casts are absent by
     * design — they are resolved on demand, not registered.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        self::boot();

        return array_keys(self::$casts);
    }

    /**
     * Drop the registry (tests) — built-ins re-register on next use.
     */
    public static function clear(): void
    {
        self::$casts = [];
        self::$resolved = [];
        self::$booted = false;
    }

    /**
     * Register the built-in casts once.
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::$casts['int'] = new IntCast();
        self::$casts['float'] = new FloatCast();
        self::$casts['bool'] = new BoolCast();
        self::$casts['json'] = new JsonCast();
        self::$casts['pgarray'] = new PgArrayCast();
        self::$casts['datetime'] = new DateTimeCast();
    }
}