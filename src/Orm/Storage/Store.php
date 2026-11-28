<?php

namespace Azera\Orm\Storage;

use Azera\Orm\ConflictTarget;

/**
 * Persistence-level seam between the ORM and any storage backend.
 *
 * Operations the EntityManager's write pipeline performs — NOT a query builder. SQL stores
 * implement it over a {@see \Azera\Db\Database}; Mongo over the
 * mongodb library. The per-situation write strategies (RETURNING matrix)
 * live in each backend. A model belongs to exactly one store, routed by
 * metadata `store` (#[Entity(store: 'name')]) — the registry key
 * EntityManager::setStore() maps to an instance. Third-party backends:
 * implement this interface, register under a name, annotate #[Entity].
 */
interface Store
{
    /**
     * Connection identity for the class described by $meta: two classes
     * sharing one txTarget share one transaction target in flush().
     * Borrowing stores (SQL) derive it from the write role; owning stores
     * return a constant token (their connection is fixed per instance).
     */
    public function txTarget(array $meta): string;
    /**
     * Persist one entity: INSERT or UPDATE (upsert when flagged).
     * Returns raw row(s) for backfill: ['row' => ?array, 'id' => int|string|null].
     *
     * @param array<string, mixed> $data column-name-keyed raw values
     * @return array{row: ?array, id: int|string|null}
     */
    public function insertOne(string $class, array $data): array;

    /**
     * Update one entity by PK values.
     *
     * @param array<string, mixed> $data    column-name-keyed changed values
     * @param array<string, mixed> $id      PK field => value
     * @return array{row: ?array, id: int|string|null}
     */
    public function updateOne(string $class, array $data, array $id): array;

    /**
     * Atomic UPSERT: INSERT ... ON CONFLICT DO UPDATE (SQL) / updateOne
     * with upsert:true (Mongo). Existence is resolved BY THE DATABASE at
     * write time: no prior SELECT, no insert-or-update guess.
     *
     * $on selects the uniqueness key the statement conflicts on:
     *
     * - null: the caller-set PK — $data must carry every PK column.
     * - a {@see \Azera\Orm\ConflictTarget}: that column list (plus its
     *   predicate, when the index is partial). Required whenever the PK is
     *   database-generated or the real uniqueness lives elsewhere.
     *
     * $updateColumns restricts what the CONFLICT ACTION may write; null
     * writes every non-target column present in $data. Needed on wide rows
     * where a full-payload update would clobber state the caller does not
     * own (a credential revision, a counter, a workspace binding). An empty
     * list means "insert if absent, change nothing on conflict".
     *
     * Returns raw row(s) for backfill, same contract as insertOne
     * (RETURNING * when unset non-PK columns should refresh DB defaults).
     *
     * @param array<string, mixed>                    $data          column-name-keyed raw values (PK included)
     * @param ConflictTarget|null          $on            key to conflict on; null = the PK
     * @param list<string>|null                       $updateColumns columns the conflict action may write; null = all non-target
     * @return array{row: ?array, id: int|string|null}
     */
    public function upsertOne(
        string $class,
        array $data,
        ?ConflictTarget $on = null,
        ?array $updateColumns = null,
    ): array;

    /**
     * Delete one entity by PK values.
     * @param array<string, mixed> $id PK field => value
     */
    public function deleteOne(string $class, array $id): void;

    /**
     * Read raw rows. Returns plain assoc rows (no ResultSet).
     *
     * @param class-string $class
     * @param array $where    PK field => value, or field                          => value
     * @return list<array<string, mixed>>
     */
    public function findBy(string $class, array $where): array;

    /**
     * Read one raw row by PK values (null when missing).
     *
     * @param class-string $class
     * @param array<string, mixed> $id PK field => value
     */
    public function findByPk(string $class, array $id): ?array;

    /**
     * Count matching rows.
     * @param array $where field => value
     */
    public function count(string $class, array $where = []): int;

    /* --------------------------------------------------- transactions */

    /**
     * Open a transaction for $meta's write target. $meta (the scheduled
     * class's metadata) lets a store with per-class routing open the tx on
     * the class's OWN connection; a store MAY hold SEVERAL txs at once —
     * one per distinct connection target (PdoStore's tx map) — and a
     * begin() for an ALREADY-OPEN target must join it (no nesting).
     * Null = the store's default target.
     */
    public function begin(?array $meta = null): void;

    /**
     * Commit the tx on $meta's write target — a tx THIS store began
     * (caller-opened txs are joined by routing and must never be
     * committed/rolled back by a store). Null meta commits EVERY tx the
     * store began (the legacy bare-call semantic, generalized to the map).
     */
    public function commit(?array $meta = null): void;

    /**
     * Rollback — same target addressing as {@see Store::commit()}.
     */
    public function rollback(?array $meta = null): void;

    /**
     * Whether a transaction (or savepoint level) is active on $meta's
     * write target — store-begun OR caller-opened (the EM joins either
     * instead of double-beginning). Null meta: any of the store's targets.
     */
    public function inTransaction(?array $meta = null): bool;

    /**
     * Return $meta enriched (or throw for dishonorable attribute combos).
     * MUST stay JSON-serializable — the result feeds the L2 metadata cache.
     *
     * Beyond pkMode, a store may contribute the recognized key
     * `castExclusions`: a list<string> of column TYPES whose registered
     * cast ({@see \Azera\Orm\Casting\Casts}) is SUPPRESSED by default —
     * wire formats the backend owns natively (mongo: 'json', 'pgarray',
     * 'datetime' — BSON maps PHP arrays and DateTimeInterface itself).
     * Per-column overrides: #[Column(cast: true)] forces the cast where
     * the store excluded it; #[Column(cast: false)] suppresses it where
     * the store would apply it. Resolved per column at compile time
     * (metadata 'cast' => bool) — the write pipeline stays metadata-driven
     * like everything else the EM consumes.
     *
     * @param array<string, mixed> $meta the freshly compiled generic metadata
     * @param \ReflectionClass<object> $class reflection of the compiled class
     * @return array<string, mixed>
     */
    public function enrichMetadata(array $meta, \ReflectionClass $class): array;
}
