<?php

namespace Azera\Orm;

/**
 * The uniqueness key an UPSERT conflicts on: a column list plus, when the
 * index is PARTIAL, its predicate.
 *
 * Without this, {@see EntityManager::upsert()} can only conflict on the
 * primary key — which is useless for the two shapes that actually need an
 * atomic upsert:
 *
 * - a DB-GENERATED primary key (uuid/int), where the caller has no PK to
 *   name before the row exists, and
 * - a natural key such as `stripe_customer_id`, where the PK is incidental
 *   and the uniqueness the caller cares about is a separate index.
 *
 * The predicate is not cosmetic. PostgreSQL matches an `ON CONFLICT` target
 * against a unique index by inference, and a PARTIAL index is only inferred
 * when the statement repeats its predicate. Both halves of this object
 * therefore travel together into the statement; dropping the predicate turns
 * a working upsert into "there is no_unique_constraint matching the ON
 * CONFLICT specification".
 *
 * Immutable: a target is queued on a {@see Node} and must not change under
 * a later flush.
 */
final class ConflictTarget
{
    /**
     * @param non-empty-list<string> $columns column names (metadata column
     *                                        names — the store form, so a
     *                                        renamed #[Column(name:)] property
     *                                        is addressed by its COLUMN name)
     * @param ?string                $where   the partial index predicate, in
     *                                        raw SQL (e.g.
     *                                        'stripe_customer_id IS NOT NULL');
     *                                        null = a full unique index. Never
     *                                        bindable: it is DDL-equivalent
     *                                        structure, so it is emitted
     *                                        verbatim and must come from code,
     *                                        never from user input.
     */
    private function __construct(
        public readonly array $columns,
        public readonly ?string $where = null,
    ) {}

    /**
     * A conflict target on $columns, optionally constrained to the partial
     * index whose predicate is $where.
     *
     * @param non-empty-list<string> $columns
     */
    public static function columns(array $columns, ?string $where = null): self
    {
        if ($columns === []) {
            throw new \InvalidArgumentException(
                'A conflict target needs at least one column; omit the target entirely to conflict on the primary key.'
            );
        }

        foreach ($columns as $column) {
            if (!\is_string($column)) {
                throw new \InvalidArgumentException(
                    'Conflict target column must be a string, ' . \get_debug_type($column) . ' given.'
                );
            }
            if ($column === '') {
                throw new \InvalidArgumentException(
                    'Conflict target column must not be an empty string.'
                );
            }
        }

        return new self($columns, $where);
    }

    /** True when the target addresses a partial (predicate) index. */
    public function isPartial(): bool
    {
        return $this->where !== null;
    }
}
