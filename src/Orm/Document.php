<?php

namespace Azera\Orm;

use Azera\AppContext;

/**
 * Base class for MongoDB-backed objects (pairs with #[Entity(store: 'mongo')]).
 *
 * FACADE over the {@see EntityManager}: the whole static surface —
 * {@see find()}, {@see findOrFail()}, {@see findOne()}, {@see findAll()},
 * {@see create()}, {@see upsert()}, {@see firstOrCreate()},
 * {@see updateOrCreate()} — is shared with {@see Model} via
 * {@see Concerns\EntityFacade} and goes through the Store seam, so it behaves
 * identically on documents and on SQL models. Writes delegate to the EM's
 * pipeline (persist + flush): documents and SQL models run the SAME
 * diff -> order -> transaction path and land in the SAME request-scoped heap.
 * The #[Entity] attribute's `store` selects the registered store type
 * (EntityManager::setStore()).
 *
 * Deliberately NOT a {@see Model} subclass: metadata PK resolution keys off
 * `isSubclassOf(Model::class)` to apply the SQL `['id']` chain, so extending
 * it would break the document `_id` convention. For the same reason the
 * builder-based readers (`query()`, `with()`, `fresh()`, `exists()`,
 * `count()`) are NOT available here — they compile SQL and would query the
 * wrong database; use the EntityManager for anything the facade cannot
 * express.
 *
 * The EM's heap-node diff is authoritative — hydrated documents are
 * heap-tracked, so persist() schedules an UPDATE only when fields actually
 * changed.
 */
abstract class Document
{
    use Concerns\EntityFacade;

    /**
     * Which store type handles this document (the registry key
     * EntityManager::setStore() maps to an instance). Mirrors the
     * #[Entity(store: ...)] attribute; the attribute is the authority
     * (it compiles into metadata).
     */
    public function store(): string
    {
        return Metadata::for(static::class)['store'] ?? 'sql';
    }

    /**
     * Save via the EM: INSERT when untracked, diff-UPDATE when managed.
     * The EM's heap-node diff is authoritative (hydrated documents are
     * heap-tracked with a baseline snapshot), so we schedule FIRST,
     * check whether anything was actually queued, and only then flush.
     */
    public function save(): bool
    {
        $em = AppContext::instance()->entityManager();

        $em->persist($this);

        return $em->flushEntity($this);
    }

    /**
     * Delete this document through the {@see EntityManager} (remove + flush —
     * one delete by the full key).
     *
     * The key check below is kept for its facade-specific message; the
     * EntityManager enforces the same invariant (and adopts untracked
     * instances), so this only needs to fail early with a clearer error.
     *
     * @throws \RuntimeException when any PK field is unset
     */
    public function delete(): bool
    {
        $pkFields = Metadata::for(static::class)['pkFields'];

        if ($pkFields === []) {
            throw new \RuntimeException(
                'Cannot delete ' . static::class . ': no primary key is defined. '
                . 'Declare one with #[Column(pk: true)] or an idFields() override.'
            );
        }

        foreach ($pkFields as $field) {
            if (!isset($this->{$field})) {
                throw new \RuntimeException(sprintf(
                    "Cannot delete %s: ID field '%s' not set (key fields: %s)",
                    static::class,
                    $field,
                    implode(', ', $pkFields)
                ));
            }
        }

        AppContext::instance()->entityManager()->remove($this)->flush();

        return true;
    }

    /* -------------------------------------------------------------
     *  DIRTY STATE
     * ------------------------------------------------------------- */

    /**
     * Whether any field differs from the heap baseline (untracked entity:
     * true when any metadata column has a set value).
     */
    public function hasChanged(): bool
    {
        return AppContext::instance()->entityManager()->isDirty($this);
    }

    /**
     * Field-name-keyed map of values that differ from the heap baseline
     * (untracked entity: all set values).
     *
     * @return array<string, mixed>
     */
    public function changedData(): array
    {
        return AppContext::instance()->entityManager()->dirtyData($this);
    }

    /**
     * Revert all properties to the values recorded in the heap node
     * snapshot (the loadState() replacement). No-op for untracked entities.
     */
    public function loadState(): static
    {
        AppContext::instance()->entityManager()->revert($this);
        return $this;
    }
    /**
     * Re-read this document's row from storage and refresh the instance
     * IN PLACE (current values + synced snapshot). Returns $this, or NULL
     * when the row is gone in storage (detached). Throws for untracked
     * documents and documents with scheduled unflushed writes.
     */
    public function refresh(): ?static
    {
        $found = AppContext::instance()->entityManager()->refresh($this);

        return $found instanceof static ? $found : null;
    }
}