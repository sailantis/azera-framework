<?php

namespace Azera\Orm;

use Azera\AppContext;
use Azera\Lifecycle\RequestScoped;
use Azera\Orm\Casting\Casts;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\Store;
use Azera\Orm\Storage\Stores;

/**
 * The entity manager: identity map + write pipeline, consolidated.
 *
 * One instance per request (registered request-scoped in AppContext).
 * The Active-Record facades ({@see \Azera\Orm\Model},
 * {@see \Azera\Orm\Document}) delegate here, so facade-style and
 * EM-direct use share ONE write pipeline: diff -> topological order ->
 * transaction -> ID backfill.
 *
 * Reads probe the identity map first - find() hit = the same instance,
 * miss = one Store read + FastHydrator onto the shared heap. Works for
 * SQL models (PdoStore) and Mongo documents.
 *
 * The write pipeline used to be a separate UnitOfWork class; it is merged
 * here because it had exactly one caller and no public surface beyond
 * persist/remove/flush. flush() = diff -> commands -> topological order
 * (owners before dependents, so auto-generated owner PKs backfill into
 * dependents' FK values) -> execute -> backfill IDs -> mark MANAGED,
 * inside the Store's transaction (nested transactions already supported
 * by Database). Fast path: a trivial single-entity flush adds only
 * scheduling overhead, and no tx when one is already open (joins the
 * caller's tx).
 *
 * Storage-agnostic: writes execute through the {@see Store} seam resolved
 * from class metadata (#[Entity(store: ???)] - an opaque registry key). The
 * SQL shapes and the RETURNING matrix (pk_set / returning_id /
 * returning_all / last_insert_id) live in each Store backend; flush
 * consumes their normalized ['row' => ?array, 'id' => ?scalar] results
 * for identity backfill.
 *
 * RequestScoped: {@see resetState()} wipes the heap and drops scheduled
 * writes between requests in persistent workers (non-negotiable - same
 * contract as Heap).
 */
final class EntityManager implements RequestScoped
{
    public function __construct(
        private Heap $heap,
        private ?object $db = null,
    ) {}

    /* ------------------------------------------------------------ accessors */

    /**
     * The shared identity map.
     */
    public function heap(): Heap
    {
        return $this->heap;
    }

    /* ---------------------------------------------------------------- reads */

    /**
     * Load one entity by PK values: heap probe first, Store read on miss,
     * hydration onto the shared heap.
     *
     * $fresh=false (default): a heap hit returns the tracked instance
     * WITHOUT touching the store - the identity-map behavior.
     *
     * $fresh=true: the row is RE-READ from the Store and applied onto the
     * tracked instance in place ({@see refresh()}) - same object, current
     * values. Use this for stale-read-sensitive work (polling tasks,
     * cross-request workers between resetState() boundaries). Entities
     * with scheduled (unflushed) writes must NOT be fresh-read - refresh()
     * throws instead of silently discarding pending work.
     *
     * @param class-string         $class
     * @param array<string, mixed> $id PK field => value
     */
    public function find(string $class, array $id, bool $fresh = false): ?object
    {
        // Identity hit: the exact same object this request already loaded.
        $node = $this->heap->findById($class, $id);
        if ($node !== null) {
            if (!$fresh) {
                return $this->heap->entityFor($node);
            }

            // Fresh hit: same instance, refreshed from the store.
            $entity = $this->heap->entityFor($node);
            if ($entity === null) {
                return null;
            }

            return $this->refresh($entity);
        }

        // Miss: one SELECT via the Store seam, hydrate into the heap.
        $row = $this->storeFor($class)->findByPk($class, $id);
        if ($row === null) {
            return null;
        }

        [$entity] = FastHydrator::for($class)->hydrate($this->heap, $row);

        return $entity;
    }

    /**
     * Load all entities matching field => value conditions.
     *
     * $fresh=true refreshes already-tracked entities in place from the
     * fresh rows (same instances, current values); entities with pending
     * scheduled writes keep their in-request state.
     *
     * @param class-string         $class
     * @param array<string, mixed> $where
     * @return list<object>
     */
    public function findBy(string $class, array $where, bool $fresh = false): array
    {
        $rows = $this->storeFor($class)->findBy($class, $where);

        return $this->hydrateRows($class, $rows, $fresh);
    }

    /**
     * Re-read an entity's row from the Store and refresh the tracked
     * instance IN PLACE: current row values onto the entity, node snapshot
     * synced as the new diff baseline. Identity is preserved - the caller
     * keeps its reference, the data is current. The escape hatch that
     * keeps the identity-map's correctness (one row = one object, no lost
     * in-request writes) while serving freshness-sensitive reads
     * (polling, long-lived workers between request boundaries).
     *
     * Returns the entity when refreshed. Returns NULL when the row is
     * GONE in storage - the entity is detached (the identity map mirrors
     * the store; a tracked ghost would keep coming back on heap-hit
     * reads). Guards: untracked entities throw (nothing to refresh
     * against - find()/track() first); entities with scheduled unflushed
     * writes throw (a re-read would clobber queued work - flush() first).
     */
    public function refresh(object $entity): ?object
    {
        $node = $this->heap->find($entity);

        if ($node === null) {
            throw new \LogicException(
                'refresh() requires a tracked entity - load it through find()/findBy()/entities(), or track() it first'
            );
        }

        if ($node->isScheduled()) {
            throw new \LogicException(
                'refresh() on an entity with scheduled (unflushed) writes would discard pending work - flush() first'
            );
        }

        $row = $this->storeFor($entity::class)->findByPk($entity::class, $node->id);
        if ($row === null) {
            // Row gone: the store is authoritative about the identity.
            // Detach so later heap-hit reads cannot resurrect the ghost.
            $this->heap->detach($entity);
            return null;
        }

        FastHydrator::for($entity::class)->apply($entity, $node, $row);

        return $entity;
    }

    /* --------------------------------------------------------------- writes */

    /**
     * Queue an entity for INSERT (or UPDATE when already managed).
     * Explicit intent - flush() sees ONLY what was persisted here
     * (the deliberate no-implicit-dirty-checking doctrine contrast).
     */
    public function persist(object $entity): static
    {
        $node = $this->heap->find($entity);

        if ($node === null) {
            $this->scheduleInsert($entity);
        } elseif ($node->state === Node::MANAGED) {
            $this->scheduleUpdate($entity);
        }
        // SCHEDULED_* states: already queued, nothing to do.

        return $this;
    }

    /**
     * Queue a single-statement UPSERT (INSERT ... ON CONFLICT DO UPDATE /
     * mongo updateOne upsert:true): the DATABASE resolves insert-vs-update
     * at write time - no prior SELECT, no insert-or-update guess, no
     * unique-violation race. Deliberately intent-based like persist(): the
     * caller asserts "row with this PK should exist afterwards", and the
     * store makes it so atomically.
     *
     * Requires a full identity (every PK field set) - the PK is the
     * conflict target. Anything less is an ordinary insert.
     */
    public function upsert(object $entity): static
    {
        $meta = Metadata::for($entity::class);

        $id = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $id[$field] = isset($entity->{$field}) ? $entity->{$field} : null;
            }
        }

        if (in_array(null, $id, true)) {
            // Incomplete identity: no conflict target to promise. Degrade
            // to a plain insert (the pre-EM Model::upsert() semantic).
            return $this->persist($entity);
        }

        $data = $this->extractData($entity, $meta);

        $node = new Node($entity::class, $id, $data, Node::SCHEDULED_UPSERT);
        $this->heap->attach($entity, $node);

        return $this;
    }

    /**
     * Queue an entity for DELETE. Never-persisted entities (or cancelled
     * pending inserts) are just dropped from identity tracking.
     */
    public function remove(object $entity): static
    {
        $node = $this->heap->find($entity);

        if ($node === null || $node->state === Node::SCHEDULED_INSERT) {
            // Never persisted (or pending insert cancelled): nothing in
            // storage to remove; drop from identity tracking.
            $this->heap->detach($entity);
            return $this;
        }

        $node->state = Node::SCHEDULED_DELETE;

        return $this;
    }

    /**
     * Execute all scheduled writes in ONE transaction
     * (diff -> order -> execute -> backfill).
     *
     * Transaction control follows the SCHEDULED WORK's (store, txTarget)
     * grouping: all scheduled classes must resolve to ONE store instance
     * AND one connection target on it - otherwise the flush spans two
     * connections and cannot be atomic, which throws (stores may relax
     * this with per-instance semantics via txTarget(); e.g. a mongo
     * store's no-op txs group under its single instance token). Use
     * {@see EntityManager::flushAll()} for write sets that legitimately
     * span connections (per-target txs, best-effort all-or-nothing).
     */
    public function flush(): void
    {
        $scheduled = $this->heap->scheduled();
        if ($scheduled === []) {
            return; // fast path: nothing to do
        }

        // Resolve every scheduled class's store UP FRONT - a missing or
        // misconfigured store must fail the flush before ANY write runs,
        // not mid-flush after earlier nodes already executed.
        $groups = $this->partitionScheduled($scheduled);

        if (\count($groups) > 1) {
            $targetList = implode(', ', array_column($groups, 'target'));
            throw new \RuntimeException(
                'flush() spans multiple connections (store type change or '
                    . "different write targets: {$targetList}) - not atomic, "
                    . 'split it into separate flushes, use flushAll() for per-target transactions, '
                    . 'or persist through separate EntityManagers.'
            );
        }

        $group = $groups[0];
        $store = $group['store'];
        $meta  = $group['firstMeta'];

        $startedTx = false;
        if (!$store->inTransaction($meta)) {
            $store->begin($meta);
            $startedTx = true;
        }

        try {
            foreach ($this->order($scheduled) as $node) {
                $this->execute($node);
            }

            if ($startedTx) {
                $store->commit($meta);
            }
        } catch (\Throwable $e) {
            if ($startedTx) {
                $store->rollback($meta);
            }
            throw $e;
        }
    }

    /**
     * Execute all scheduled writes across EVERY connection they touch.
     * The escape hatch for write sets that legitimately span multiple
     * stores/connections (e.g. SQL + mongo, or several #[Connection]
     * write roles): ONE global topological pass executes every node (an
     * owner in one group can feed its PK into a dependent in another),
     * and each store/connection target commits its own tx - begun lazily
     * when its first node executes.
     *
     * Failure semantics are best-effort all-or-nothing, mirroring
     * flush()'s shape: if any write (or a commit) throws mid-pass, every
     * tx begun SO FAR is rolled back; groups whose commit already ran
     * stay committed. Cross-connection atomicity does not exist - use
     * flush() when the whole write set shares one connection target.
     */
    public function flushAll(): void
    {
        $scheduled = $this->heap->scheduled();
        if ($scheduled === []) {
            return; // fast path: nothing to do
        }

        // Same fail-fast as flush(): resolve every scheduled class's store
        // before ANY write runs.
        $this->partitionScheduled($scheduled);

        $begun = [];
        try {
            foreach ($this->order($scheduled) as $node) {
                $meta  = Metadata::for($node->class);
                $store = $this->storeFor($node->class);

                if (!$store->inTransaction($meta)) {
                    $store->begin($meta);
                    $begun[] = [$store, $meta];
                }

                $this->execute($node);
            }

            foreach ($begun as [$store, $meta]) {
                $store->commit($meta);
            }
        } catch (\Throwable $e) {
            foreach ($begun as [$store, $meta]) {
                $store->rollback($meta);
            }
            throw $e;
        }
    }

    /**
     * Group scheduled nodes by (store instance, txTarget): the tx-partition
     * of the write set, shared by flush() (atomic single group) and
     * flushAll() (independent per-group txs). Each entry carries the store,
     * its target token, and the FIRST node's meta - the tx control calls
     * are addressed through it.
     *
     * @return list<array{store: Store, target: string, firstMeta: array<string, mixed>}>
     */
    private function partitionScheduled(array $scheduled): array
    {
        $groups     = [];
        $groupIndex = [];

        foreach ($scheduled as $node) {
            $meta   = Metadata::for($node->class);
            $store  = $this->storeFor($node->class);
            $target = $store->txTarget($meta);
            $key    = spl_object_id($store) . '|' . $target;

            if (!isset($groupIndex[$key])) {
                $groupIndex[$key] = \count($groups);
                $groups[] = ['store' => $store, 'target' => $target, 'firstMeta' => $meta];
            }
        }

        return $groups;
    }

    /* ------------------------------------------------------------ lifecycle */

    /**
     * Drop an entity from identity tracking (no storage effect).
     */
    public function detach(object $entity): void
    {
        $this->heap->detach($entity);
    }

    /* ------------------------------------------------------------- adopt */

    /**
     * Facade adoption: register an EXTERNALLY-loaded entity as MANAGED
     * with an EMPTY baseline.
     *
     * Reads that bypass the EM pipeline (FETCH_CLASS ResultSet, Paginator)
     * produce instances that are not in the heap. The facade calls adopt()
     * before persisting. Empty baseline means the next flush writes every
     * set non-PK column - the legacy blind-UPDATE parity for manually
     * built ID'd entities (and the correct semantic: the EM cannot know
     * what the DB already holds for an entity it never loaded).
     *
     * Entities loaded through EM reads (find/findBy/entities) do NOT need
     * adopt() - their heap node carries the store snapshot from hydration.
     * The returned entity is the ADOPTED instance (heap re-attach replaces
     * the node when the entity already sits under another identity).
     */
    public function adopt(object $entity): object
    {
        $meta = Metadata::for($entity::class);

        $data = $this->extractData($entity, $meta);

        $id = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $id[$field] = $data[$col['name']] ?? null;
            }
        }

        // Empty baseline: data=[] - diff sees every set column as changed.
        $node = new Node($meta['class'], $id, [], Node::MANAGED);
        $this->heap->attach($entity, $node);

        return $entity;
    }

    /**
     * Register an externally-loaded entity as MANAGED with its CURRENT
     * values as the baseline (the "already in sync" adoption for entities
     * loaded by reads the EM does not hydrate - FETCH_CLASS ResultSet,
     * Paginator). Unlike adopt(), persist() on a tracked() entity emits
     * SQL only for fields changed after the track() call.
     */
    public function track(object $entity): object
    {
        $meta = Metadata::for($entity::class);

        $data = $this->extractData($entity, $meta);

        $id = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $id[$field] = $data[$col['name']] ?? null;
            }
        }

        $node = new Node($meta['class'], $id, $data, Node::MANAGED);
        $this->heap->attach($entity, $node);

        return $entity;
    }

    /**
     * Whether the entity is tracked in the request heap.
     */
    public function contains(object $entity): bool
    {
        return $this->heap->find($entity) !== null;
    }

    /**
     * Whether the entity has scheduled work in the current flush cycle.
     */
    public function isScheduled(object $entity): bool
    {
        $node = $this->heap->find($entity);

        return $node !== null && $node->isScheduled();
    }

    /* --------------------------------------------------- dirty-state API */

    /**
     * Dirty state backed by the heap node snapshot (the ONE diff engine ???
     * Stateful's clone snapshot is gone).
     *
     * Untracked entity: every metadata column with a set value counts as
     * changed (the same "everything set is pending" semantic the old
     * no-snapshot Stateful path had). Tracked entity: current values vs
     * the node snapshot, field-name-keyed - PK columns EXCLUDED there:
     * identity, not data (the pipeline never puts a PK into an UPDATE
     * SET, so isDirty()/hasChanged() must match what flush() would
     * actually write; a mutated PK on a tracked entity is the identity
     * guard's problem, not a data diff).
     *
     * @return array<string, mixed> field name => current value
     */
    public function dirtyData(object $entity): array
    {
        $meta = Metadata::for($entity::class);
        $node = $this->heap->find($entity);

        $byCol  = [];
        $pkCols = [];
        foreach ($meta['columns'] as $field => $col) {
            $byCol[$col['name']] = $field;
            if ($col['pk']) {
                $pkCols[$col['name']] = true;
            }
        }

        if ($node === null) {
            $data = $this->extractData($entity, $meta);
            // Mirror Stateful's no-snapshot semantic: all set fields
            // changed. extractData is COLUMN-keyed - remap to field names
            // so the untracked branch is field-name-keyed like the
            // tracked branch (the documented contract of this method).
            $out = [];
            foreach (array_filter($data, fn($v) => $v !== null) as $col => $value) {
                $out[$byCol[$col] ?? $col] = $value;
            }

            return $out;
        }

        $current = $this->extractData($entity, $meta);

        $changed = [];
        foreach ($current as $col => $value) {
            // Skip PK columns as they are identity, not data
            if (isset($pkCols[$col])) {
                continue;
            }
            if ($value !== ($node->data[$col] ?? null)) {
                $changed[$byCol[$col] ?? $col] = $value;
            }
        }

        return $changed;
    }

    /**
     * Whether the entity differs from its heap baseline (untracked entity:
     * true - it has pending state that adopt+flush would write).
     */
    public function isDirty(object $entity): bool
    {
        return $this->dirtyData($entity) !== [];
    }

    /**
     * Revert the entity's properties to the values recorded in its heap
     * node snapshot (the loadState() replacement). No-op for untracked
     * entities - nothing to revert to.
     */
    public function revert(object $entity): void
    {
        $node = $this->heap->find($entity);
        if ($node === null) {
            return;
        }

        $meta  = Metadata::for($entity::class);
        $byCol = [];
        foreach ($meta['columns'] as $field => $col) {
            $byCol[$col['name']] = $field;
        }

        $hydrator = FastHydrator::for($entity::class);

        foreach ($node->data as $colName => $value) {
            $field = $byCol[$colName] ?? null;
            if ($field !== null) {
                // node->data holds the raw store representation - decode
                // casted columns before assigning onto the entity through
                // the shared write-back gate (the column's resolved cast
                // policy plus the nullability contract).
                $hydrator->put($entity, $field, $value);
            }
        }
    }

    /**
     * Wipe ALL tracked state (identity + scheduled writes). Scheduled
     * work is dropped, NOT flushed - explicit clear means "forget".
     */
    public function clear(): void
    {
        $this->heap->resetState();
    }

    /**
     * Request-scoped hook: wipe the identity map + any scheduled writes
     * between requests in persistent workers. Also drops the memoized
     * fallback store - a worker re-pointing DatabaseManager roles (tenant
     * swap) must not keep a stale-borrowed store; the next storeFor()
     * rebuilds it from the then-current manager.
     */
    public function resetState(): void
    {
        $this->heap->resetState();
    }

    /* ------------------------------------------------------- scheduling */

    /**
     * NEW node: capture PK values (may be empty for auto-increment),
     * record raw data, attach to heap, schedule INSERT.
     */
    private function scheduleInsert(object $entity): void
    {
        $meta = Metadata::for($entity::class);
        $data = $this->extractData($entity, $meta);

        $id = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $id[$field] = $data[$field] ?? null;
            }
        }

        $node = new Node($entity::class, $id, $data, Node::SCHEDULED_INSERT);
        $this->heap->attach($entity, $node);
    }

    /**
     * MANAGED entity: guard the identity, then diff against the node
     * snapshot; schedule UPDATE if dirty.
     */
    private function scheduleUpdate(object $entity): void
    {
        $node = $this->heap->find($entity);
        if ($node === null) {
            return;
        }

        $meta = Metadata::for($entity::class);

        // Identity guard.
        foreach ($this->currentIdentity($entity, $meta) as $field => $value) {
            $snapshot = $node->id[$field] ?? null;
            $cast     = Casts::forColumn($meta['columns'][$field]);
            if ($value !== ($cast === null ? $snapshot : $cast->decode($snapshot))) {
                throw new \LogicException(
                    "Cannot mutate the identity (PK field '{$field}') of a tracked {$node->class}: "
                        . 'the pipeline updates rows BY their PK, so a changed PK would be dropped or target the wrong row. '
                        . 'detach() the entity and persist() it as a new one (delete+insert semantics), or use the query builder for PK rewrites.'
                );
            }
        }

        $diff = $this->diff($entity, $node, $meta);
        if ($diff === []) {
            return; // clean: the pipeline does NOT write unchanged entities
        }

        $node->data          = array_merge($node->data, $diff);
        $node->changedFields = array_keys($diff);
        $node->state         = Node::SCHEDULED_UPDATE;
    }

    /* --------------------------------------------------------- diffing */

    /**
     * Entity vs node snapshot diff, restricted to metadata columns.
     * Scalar-only comparison (the heap stores scalar row values).
     * PK columns are identity - never part of an UPDATE SET (changing a
     * PK is delete+insert semantics, and legacy saves never wrote them;
     * PK mutations on tracked entities throw in scheduleUpdate()'s
     * identity guard, so they never reach here as data).
     */
    private function diff(object $entity, Node $node, array $meta): array
    {
        $current = $this->extractData($entity, $meta);
        $changed = [];

        $pkCols = [];
        foreach ($meta['columns'] as $col) {
            if ($col['pk']) {
                $pkCols[$col['name']] = true;
            }
        }

        foreach ($current as $col => $value) {
            if (isset($pkCols[$col])) {
                continue;
            }
            $orig = $node->data[$col] ?? null;
            if ($value !== $orig) {
                $changed[$col] = $value;
            }
        }

        return $changed;
    }

    /* -------------------------------------------------------- ordering */

    /**
     * Topological order: entities whose class is the TARGET of another
     * scheduled entity's BelongsTo (owners) flush first, so auto-generated
     * owner PKs backfill into dependents' FKs. Everything else keeps
     * attach order. Depth computed over metadata relations; O(n) classes.
     */
    private function order(array $nodes): array
    {
        if (\count($nodes) < 2) {
            return $nodes;
        }

        // Class-level depth: owner classes (targets of belongsTo) get
        // depth 0, dependents 1, etc.
        $depth = [];
        foreach ($nodes as $node) {
            $depth[$node->class] = $depth[$node->class] ?? 0;
        }

        foreach ($nodes as $node) {
            foreach (Metadata::for($node->class)['relations'] as $rel) {
                if ($rel['type'] === 'belongsTo') {
                    $owner = $rel['target'];
                    $depth[$owner] = $depth[$owner] ?? 0;
                    // dependent's depth must exceed its owner's
                    $depth[$node->class] = max($depth[$node->class] ?? 0, $depth[$owner] + 1);
                }
            }
        }

        usort($nodes, fn($a, $b) => ($depth[$a->class] ?? 0) <=> ($depth[$b->class] ?? 0));

        return $nodes;
    }

    /* ------------------------------------------------------- execution */

    private function execute(Node $node): void
    {
        switch ($node->state) {
            case Node::SCHEDULED_INSERT:
                $this->executeInsert($node);
                break;
            case Node::SCHEDULED_UPDATE:
                $this->executeUpdate($node);
                break;
            case Node::SCHEDULED_UPSERT:
                $this->executeUpsert($node);
                break;
            case Node::SCHEDULED_DELETE:
                $this->executeDelete($node);
                break;
        }
    }

    private function executeInsert(Node $node): void
    {
        $meta   = Metadata::for($node->class);
        $entity = $this->entityFor($node);

        // Columns the caller actually set (null = not set - omitted from
        // INSERT so DB defaults apply).
        $data = $this->extractData($entity, $meta);
        $set  = array_filter($data, fn($v) => $v !== null);

        // Store executes the INSERT with its own strategy matrix and
        // returns the normalized backfill payload.
        $result = $this->storeFor($node->class)->insertOne($node->class, $set);

        // Mark MANAGED BEFORE applyRow/backfill: both re-attach a NEW node
        // to the heap carrying $node->state - setting the state after that
        // would only flip the ORPHANED old node, leaving the heap entry
        // stuck in SCHEDULED_INSERT forever (next persist - duplicate
        // INSERT; exposed by the mongo live round-trip, latent for SQL
        // id-backfill inserts as well).
        $node->state = Node::MANAGED;

        if (isset($result['row']) && is_array($result['row'])) {
            $this->applyRow($node, $entity, $result['row']);
        } elseif (($result['id'] ?? null) !== null) {
            $pk = $this->pkColumn($meta);
            $this->backfill($node, $entity, [$pk['name'] => $result['id']]);
        }
    }

    /**
     * INSERT ... ON CONFLICT DO UPDATE (or mongo updateOne upsert:true).
     * Single atomic statement: insert path AND update path in one round
     * trip. Backfill mirrors executeInsert's RETURNING matrix (full row
     * when the store refreshed unset columns, else the id).
     */
    private function executeUpsert(Node $node): void
    {
        $meta   = Metadata::for($node->class);
        $entity = $this->entityFor($node);

        // Full caller payload (nulls dropped upstream so DB defaults
        // survive); the PK doubles as the conflict target.
        $data = $this->extractData($entity, $meta);
        $set  = array_filter($data, fn($v) => $v !== null);

        $result = $this->storeFor($node->class)->upsertOne($node->class, $set);

        $node->state = Node::MANAGED;

        if (isset($result['row']) && is_array($result['row'])) {
            $this->applyRow($node, $entity, $result['row']);
        } elseif (($result['id'] ?? null) !== null) {
            $pk = $this->pkColumn($meta);
            $this->backfill($node, $entity, [$pk['name'] => $result['id']]);
        }
    }

    /**
     * UPDATE ... WHERE pk = identity. Writes only changed columns.
     */
    private function executeUpdate(Node $node): void
    {
        $meta = Metadata::for($node->class);

        // scheduleUpdate() set changedFields (COLUMN names) and merged the
        // diff into node->data - the UPDATE writes exactly those columns.
        $changed = array_intersect_key($node->data, array_flip($node->changedFields));
        if ($changed === []) {
            $node->state = Node::MANAGED;
            return;
        }

        // WHERE uses the identity captured at schedule time.
        $idWhere = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $idWhere[$col['name']] = $node->id[$field] ?? null;
            }
        }

        $this->storeFor($node->class)->updateOne($node->class, $changed, $idWhere);

        $node->state = Node::MANAGED;
    }

    /**
     * DELETE ... WHERE pk = identity. Detaches the entity afterwards.
     */
    private function executeDelete(Node $node): void
    {
        $meta = Metadata::for($node->class);

        $idWhere = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $idWhere[$col['name']] = $node->id[$field] ?? null;
            }
        }

        $this->storeFor($node->class)->deleteOne($node->class, $idWhere);

        $node->state = Node::DELETED;

        $entity = $this->entityFor($node);
        if ($entity !== null) {
            $this->heap->detach($entity);
        }
    }

    /* ---------------------------------------------- data extraction */

    /**
     * Entity -> raw store row keyed by COLUMN NAME (store representation).
     * Values whose column RESOLVES to a cast are ENCODED here (json -> text,
     * pg array -> literal; scalar casts are encode no-ops); null stays null;
     * isset() (never a bare read) so uninitialized typed properties don't
     * throw. The cast policy is per-column METADATA (resolved at compile
     * time from #[Column(cast:)] vs the store's castExclusions - e.g. mongo
     * excludes 'json'/'datetime' because BSON owns those wire formats):
     * excluded columns pass values through RAW, the driver owns the mapping.
     *
     * This is the single encode choke point: every write path (schedule,
     * diff, adopt, track, dirtyData) funnels through it, so node->data and
     * every Store payload hold the raw store representation and diff()'s
     * `!==` compares like with like.
     */
    private function extractData(object $entity, array $meta): array
    {
        $data = [];
        foreach ($meta['columns'] as $field => $col) {
            $value = isset($entity->{$field}) ? $entity->{$field} : null;

            if (($cast = Casts::forColumn($col)) !== null) {
                $value = $cast->encode($value);
            }

            $data[$col['name']] = $value;
        }

        return $data;
    }

    /**
     * The entity's CURRENT PK values, decoded to the PHP representation
     * hydration puts on the entity (cast decode applied) - so the
     * identity guard compares like with like even when the node snapshot
     * captured driver-stringified numerics (entity holds int 7, snapshot
     * '7').
     *
     * @return array<string, mixed> PK field => decoded value (null when unset)
     */
    private function currentIdentity(object $entity, array $meta): array
    {
        $id = [];
        foreach ($meta['columns'] as $field => $col) {
            if (!$col['pk']) {
                continue;
            }
            $value = isset($entity->{$field}) ? $entity->{$field} : null;
            $cast  = Casts::forColumn($col);
            $id[$field] = $cast === null ? $value : $cast->decode($value);
        }

        return $id;
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * Resolve the entity object for a node via the heap's oid index.
     */
    private function entityFor(Node $node): ?object
    {
        return $this->heap->entityFor($node);
    }

    /**
     * First PK column from metadata.
     */
    private function pkColumn(array $meta): array
    {
        foreach ($meta['columns'] as $col) {
            if ($col['pk']) {
                return $col;
            }
        }

        throw new \RuntimeException("No PK column in metadata for {$meta['class']}");
    }

    /**
     * Write values back onto the entity + node. Casted columns DECODE for
     * the entity (hydration's PHP representation - the id backfill of a
     * casted PK and RETURNING * columns land as PHP values, not raw store
     * strings); the snapshot keeps the canonical store form, computed from
     * the property's ACTUAL value so it matches extractData() exactly.
     *
     * The decode + null gate + canonicalization is delegated to
     * {@see FastHydrator::put()} - the ONE write-back entry
     * point, so this path cannot drift from hydration (it is where a
     * `cast: false` typed column used to pick up a phantom UPDATE).
     */
    private function backfill(Node $node, object $entity, array $values): void
    {
        $meta     = Metadata::for($node->class);
        $hydrator = FastHydrator::for($node->class);

        foreach ($values as $colName => $value) {
            foreach ($meta['columns'] as $field => $col) {
                if ($col['name'] === $colName) {
                    $values[$colName] = $hydrator->put($entity, $field, $value);
                    $node->data[$colName] = $values[$colName];
                }
            }
        }

        // Refresh node identity.
        $id = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $id[$field] = $entity->{$field} ?? null;
            }
        }

        $this->heap->attach($entity, new Node(
            $node->class,
            $id,
            array_merge($node->data, $values),
            $node->state,
        ));
    }

    /**
     * Apply a full RETURNING * row onto the entity + node.
     */
    private function applyRow(Node $node, object $entity, array $row): void
    {
        $meta = Metadata::for($node->class);

        foreach ($meta['columns'] as $col) {
            // Warm decode errors early: validate ALL row values before any
            // assignment mutates the entity (RETURNING * rows are store
            // representation; the column's resolved cast policy gates it).
            if (($cast = Casts::forColumn($col)) !== null) {
                $cast->decode($row[$col['name']] ?? null);
            }
        }

        // Assignment + snapshot sync happen in backfill() - it decodes
        // casted columns for the entity and canonicalizes the snapshot.
        $this->backfill($node, $entity, $row);
    }

    /* ---------------------------------------------------------- reads */

    /**
     * Hydrate raw rows onto the shared heap, in row order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<object>
     */
    private function hydrateRows(string $class, array $rows, bool $fresh = false): array
    {
        $hydrator = FastHydrator::for($class);
        $out      = [];
        foreach ($rows as $row) {
            [$entity] = $hydrator->hydrate($this->heap, $row, $fresh);
            if ($entity !== null) {
                $out[] = $entity;
            }
        }
        return $out;
    }

    /* ----------------------------------------------------- store seam */

    /**
     * Resolve the Store for a class by metadata `store` type - a plain
     * registry lookup in the context-attached Stores holder (setStore()).
     * The type comes from metadata (#[Entity(store: 'name')]) - the hard
     * routing guarantee: a class NEVER falls into another type's store
     * regardless of what is registered, because lookup is keyed by the
     * type alone.
     *
     * Fallback (direct construction - tests, scripts, holder-less
     * contexts): ONE PdoStore per EntityManager, memoized, for the
     * default 'sql' type ONLY. Safe to cache because PdoStore owns NO
     * connections - it resolves the live Database from its
     * DatabaseManager per operation. Without an injected Database it
     * shares the context's DatabaseManager directly (getOrDefault on the
     * read/write roles falls back to the manager's default role, so role
     * re-registrations - tenant swaps in workers - are picked up like
     * Model::readConnection()). Any OTHER unregistered type throws - a
     * SQL fallback would silently write the wrong backend. The db param
     * keeps positional compatibility with pre-seam callers.
     */
    private function storeFor(string $class): Store
    {
        $meta = $class !== '' ? Metadata::for($class) : null;
        $type = $meta['store'] ?? 'sql';

        $ctx   = AppContext::instance();
        $store = $ctx->get(Stores::class)->tryGet($type);
        if ($store !== null) {
            return $store;
        }

        // No store registered for this type: the SQL fallback is the ONLY
        // fallback (zero-config path); anything else must be registered.
        throw new \RuntimeException(
            "No store registered for type '{$type}' (class {$class}). " .
                'Register it via EntityManager::setStore(\'' . $type . '\', $store) - ' .
                'or annotate the class with #[Entity(store: ???)] pointing at a registered type.'
        );
    }

    /**
     * Register a Store under a TYPE NAME - the single routing axis.
     * Metadata `store` (#[Entity(store: ...)]) selects it per class.
     * A connection-owning backend with multiple clients registers one
     * type per client ('mongo-eu', 'mongo-us'): the type name IS the
     * discriminator - there is no role level.
     */
    public function setStore(string $type, Store $store): static
    {
        $ctx = AppContext::instance();
        $ctx->get(Stores::class)->set($type, $store);

        return $this;
    }

}