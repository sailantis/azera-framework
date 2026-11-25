<?php

namespace Azera\Orm\Concerns;

use Azera\AppContext;
use Azera\Orm\Metadata;

/**
 * The static facade surface that works on EVERY store, shared by the facade
 * base classes ({@see \Azera\Orm\Model}, {@see \Azera\Orm\Document}).
 *
 * WHY A TRAIT: the two facades sit in separate hierarchies on purpose —
 * `Document` must NOT extend `Model`, because metadata PK resolution keys off
 * `isSubclassOf(Model::class)` to apply the SQL `['id']` chain, which would
 * break the document `_id` convention. Sharing the surface without sharing
 * the type keeps both the routing guarantee and the API parity.
 *
 * WHY THESE METHODS ONLY: everything here goes through the EntityManager's
 * Store seam (`find()`, `findBy()`, `persist()`, `upsert()`), so it behaves
 * identically on SQL and on documents. The builder-based members
 * (`query()`, `with()`, `fresh()`, `exists()`, `count()`) compile SQL and are
 * therefore declared on `Model` alone, where `Query` refuses a non-SQL store
 * loudly instead of querying the wrong database.
 *
 * Writes stay polymorphic: `create()`, `firstOrCreate()` and
 * `updateOrCreate()` call `static::save()`, so each facade keeps its own
 * insert/update policy.
 *
 * Identity: a read hit returns the request-scoped heap instance, so a facade
 * read and an EM read of the same row are the SAME object.
 */
trait EntityFacade
{
    /* --------------------------------------------------------------
     *  READS
     * -------------------------------------------------------------- */

    /**
     * Find by its ID(s) through the EntityManager: heap probe first, one
     * Store read on miss, hydration onto the shared heap. The returned
     * instance is identity-mapped — the same row read twice in one request
     * yields the same object.
     *
     * $fresh=true re-reads the row and refreshes the tracked instance IN
     * PLACE (same object, current values) — the polling-safe variant.
     * Throws when the entity carries scheduled unflushed writes.
     *
     * @param mixed $id Single ID value, or array of ID values (numeric list
     *                  matching pkFields order, or field => value map for
     *                  composite keys)
     */
    public static function find(mixed $id, bool $fresh = false): ?static
    {
        $idMap = self::mapId($id, Metadata::for(static::class)['pkFields']);

        $found = AppContext::instance()->entityManager()->find(static::class, $idMap, $fresh);

        return $found instanceof static ? $found : null;
    }

    /**
     * Find by ID(s) or throw when nothing matches.
     *
     * @param mixed $id Single ID value or array of ID values (composite keys)
     * @param bool $fresh Re-read the row and refresh the tracked instance in place
     * @throws \RuntimeException when not found
     */
    public static function findOrFail(mixed $id, bool $fresh = false): static
    {
        $found = static::find($id, $fresh);
        if ($found === null) {
            throw new \RuntimeException(static::class . ' not found');
        }

        return $found;
    }

    /**
     * Find the first match for the given conditions, or null. Conditions
     * are field => value equality, same shape the Store seam accepts.
     *
     * $fresh=true refreshes already-tracked hits in place (same instance,
     * current values).
     *
     * @param array<string, mixed> $conditions
     */
    public static function findOne(array $conditions, bool $fresh = false): ?static
    {
        $rows = AppContext::instance()->entityManager()->findBy(static::class, $conditions, $fresh);

        $first = $rows[0] ?? null;

        return $first instanceof static ? $first : null;
    }

    /**
     * Find all matches for the given conditions (field => value equality),
     * in store row order, as identity-mapped instances.
     *
     * @param array<string, mixed> $conditions
     * @return list<static>
     */
    public static function findAll(array $conditions = [], bool $fresh = false): array
    {
        return AppContext::instance()->entityManager()->findBy(static::class, $conditions, $fresh);
    }

    /* --------------------------------------------------------------
     *  WRITES
     * -------------------------------------------------------------- */

    /**
     * Build an instance from $values and persist it, returning it afterwards.
     *
     * Flows through {@see save()}, so the class's own policy applies (an
     * auto-generated key is backfilled by the EntityManager).
     *
     * @param array<string, mixed> $values field => value
     */
    public static function create(array $values): static
    {
        $instance = new static();

        foreach ($values as $key => $value) {
            $instance->$key = $value;
        }

        $instance->save();

        return $instance;
    }

    /**
     * Atomic UPSERT (INSERT ... ON CONFLICT DO UPDATE on SQL, `updateOne` with
     * `upsert: true` on documents): one statement, no prior SELECT — the
     * database resolves insert-vs-update, so there is no unique-violation
     * race. Every ID field must be present in $values, since the key is the
     * conflict target; on conflict, all non-ID fields from $values are
     * written.
     *
     * With an incomplete key this degrades to a plain insert (there is no
     * conflict target to promise).
     *
     * @param array<string, mixed> $values field => value (must include all ID fields)
     */
    public static function upsert(array $values): static
    {
        $instance = new static();

        foreach ($values as $key => $value) {
            $instance->$key = $value;
        }

        $em = AppContext::instance()->entityManager();
        $em->upsert($instance);
        $em->flush();

        return $instance;
    }

    /**
     * Return the first match for $conditions, or create one from
     * $conditions merged with $values.
     *
     * A read-then-write convenience, not a concurrency guarantee: two
     * callers racing the insert both miss and both create. Use
     * {@see upsert()} when the key is known and atomicity matters.
     *
     * @param array<string, mixed> $conditions field => value to match
     * @param array<string, mixed> $values additional values for the new instance
     */
    public static function firstOrCreate(array $conditions, array $values = []): static
    {
        $found = static::findOne($conditions);

        if ($found !== null) {
            return $found;
        }

        return static::create(array_merge($conditions, $values));
    }

    /**
     * Update the first match for $conditions with $values, or create one
     * from $conditions merged with $values when there is no match.
     *
     * Same read-then-write caveat as {@see firstOrCreate()}.
     *
     * @param array<string, mixed> $conditions field => value to match
     * @param array<string, mixed> $values values to assign (or merge on create)
     */
    public static function updateOrCreate(array $conditions, array $values = []): static
    {
        $found = static::findOne($conditions);

        if ($found !== null) {
            foreach ($values as $key => $value) {
                $found->$key = $value;
            }
            $found->save();

            return $found;
        }

        return static::create(array_merge($conditions, $values));
    }

    /* --------------------------------------------------------------
     *  HELPERS
     * -------------------------------------------------------------- */

    /**
     * Normalize the accepted ID shapes into the EM's field => value map.
     * A scalar requires a single-column key.
     *
     * @param list<string> $idFields
     * @return array<string, mixed>
     */
    private static function mapId(mixed $id, array $idFields): array
    {
        if (!\is_array($id)) {
            if (\count($idFields) !== 1) {
                throw new \RuntimeException(
                    'Composite key classes require an array of ID values (' . implode(', ', $idFields) . ')'
                );
            }
            return [$idFields[0] => $id];
        }

        if (\count($id) !== \count($idFields)) {
            throw new \RuntimeException(sprintf(
                'ID array count mismatch for %s: expected %d, got %d',
                static::class,
                \count($idFields),
                \count($id)
            ));
        }

        // Numeric list: values are ordered like the key fields.
        if (array_is_list($id)) {
            $map = [];
            foreach ($idFields as $i => $field) {
                if (!isset($id[$i])) {
                    throw new \RuntimeException("Missing ID value for field '{$field}'");
                }
                $map[$field] = $id[$i];
            }
            return $map;
        }

        // Associative: keys must match the key fields.
        $map = [];
        foreach ($idFields as $field) {
            if (!isset($id[$field])) {
                throw new \RuntimeException("Missing ID value for field '{$field}'");
            }
            $map[$field] = $id[$field];
        }
        return $map;
    }
}
