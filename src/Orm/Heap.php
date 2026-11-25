<?php

namespace Azera\Orm;

use Azera\Lifecycle\RequestScoped;

/**
 * Identity map: ONE node per persisted entity, keyed by class + PK values.
 *
 * Purpose is CORRECTNESS, not caching: the same DB row loaded twice in one
 * request yields the same object, and the EntityManager diffs against the
 * node snapshot instead of scanning every constructed instance. The heap is
 * request-scoped — {@see resetState()} wipes everything between requests in
 * persistent workers (non-negotiable; a leaking heap would return stale
 * entities for a new request / another tenant).
 *
 * Performance shape: two flat array lookups per access (identity index and
 * oid index), no objects allocated for lookups.
 */
final class Heap implements RequestScoped
{
    /** Prefix for synthetic slot keys holding PK-less (unflushed) nodes. */
    private const SLOT_PREFIX = "\0slot\0";

    /** @var array<string, Node> identityKey => node */
    private array $nodes = [];

    /** @var array<int, Node> spl_object_id(entity) => node */
    private array $oids = [];

    /**
     * Slots for nodes whose identity is INCOMPLETE (PK fields unset — a
     * scheduled INSERT whose PK the database will generate). They cannot
     * share the identity index (every PK-less node of a class would
     * collapse to the same key — persisting two of them silently kept
     * only the last). Each such node gets a unique synthetic slot key;
     * the slot is released when backfill/applyRow replaces the node.
     *
     * @var array<int, string> spl_object_id(entity) => slot key
     */
    private array $slots = [];

    /**
     * Retains the entity itself so its spl_object_id cannot be recycled by
     * the GC while the node is attached. Without this, an entity that only
     * lives inside the heap would be collected, its oid reused by the next
     * object, and the oid index silently corrupted.
     *
     * @var array<int, object> spl_object_id => entity
     */
    private array $entities = [];

    /**
     * Reverse index for O(1) entityFor(): spl_object_id(node) =>
     * spl_object_id(entity). entityFor() is on the flush hot path (every
     * scheduled write resolves its entity through it); a linear scan over
     * $entities made flush cost grow with total tracked entities.
     *
     * @var array<int, int> spl_object_id(node) => spl_object_id(entity)
     */
    private array $nodeOids = [];

    /**
     * Build the composite identity key for an entity.
     *
     * Assoc id arrays are canonicalized (ksort) so ['a'=>1,'b'=>2] and
     * ['b'=>2,'a'=>1] produce the SAME key. List-form id arrays keep their
     * value order (position defines the field).
     *
     * Fast path: single-PK scalar ids (the overwhelmingly common shape —
     * e.g. ['id' => 42]) skip ksort/array_is_list and build the key with
     * one interpolation. Composite keys keep the full canonicalization.
     *
     * @param class-string         $class
     * @param array<string, mixed> $id    PK field => value (all values non-null)
     */
    public static function key(string $class, array $id): string
    {
        // Fast path: exactly one PK, field name known at call site.
        if (\count($id) === 1) {
            $field = \key($id);
            return $class . '|' . $field . '=' . \current($id);
        }

        $isList = $id === [] || array_is_list($id);
        if (!$isList) {
            ksort($id);
        }

        $parts = [$class];
        foreach ($id as $field => $value) {
            $parts[] = $field . '=' . $value;
        }

        return implode('|', $parts);
    }

    /**
     * Register (or replace) the node for an entity.
     */
    public function attach(object $entity, Node $node): void
    {
        $oid = \spl_object_id($entity);

        // If this entity object was previously attached, drop its stale
        // registration (identity slot or synthetic slot) so it cannot
        // leak across identities.
        if (isset($this->oids[$oid]) && $this->oids[$oid] !== $node) {
            $stale = $this->oids[$oid];
            $this->dropNode($stale, $oid);
        }

        if ($this->hasCompleteIdentity($node->id)) {
            $key = self::key($node->class, $node->id);
        } else {
            // Incomplete identity: unique synthetic slot per entity object.
            // The scheduled INSERT's real identity arrives via backfill().
            $key = $this->slots[$oid] ??= self::SLOT_PREFIX . $node->class . '|' . $oid;
        }

        $this->nodes[$key]                    = $node;
        $this->oids[$oid]                     = $node;
        $this->entities[$oid]                 = $entity;
        $this->nodeOids[spl_object_id($node)] = $oid;
    }

    /**
     * Whether every PK value is non-null (a usable identity-map key).
     *
     * An EMPTY id set is NOT an identity: a class with no key cannot be
     * looked up, so it must take the synthetic-slot path. Returning true
     * here (the historical vacuous-truth behaviour) made every key-less
     * entity share the `class|` index entry — a second INSERT silently
     * overwrote the first's node and was never flushed.
     *
     * @param array<string, mixed> $id PK field => value
     */
    private function hasCompleteIdentity(array $id): bool
    {
        if ($id === []) {
            return false;
        }

        foreach ($id as $value) {
            if ($value === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Remove a node from the identity index (and its synthetic slot
     * registration when it occupied one).
     */
    private function dropNode(Node $node, int $oid): void
    {
        $key = self::key($node->class, $node->id);
        if (isset($this->nodes[$key]) && $this->nodes[$key] === $node) {
            unset($this->nodes[$key]);
        }
        // Also clear a synthetic slot that pointed at this node.
        if (
            ($this->slots[$oid] ?? null) !== null
                && ($this->nodes[$this->slots[$oid]] ?? null) === $node
        ) {
            unset($this->nodes[$this->slots[$oid]]);
        }
        unset($this->slots[$oid], $this->nodeOids[spl_object_id($node)]);
    }

    /**
     * Find the node for an entity OBJECT (regardless of its identity).
     */
    public function find(object $entity): ?Node
    {
        return $this->oids[\spl_object_id($entity)] ?? null;
    }

    /**
     * Find the node for a class + PK values — the identity-map hit path.
     *
     * @param class-string         $class
     * @param array<string, mixed> $id
     */
    public function findById(string $class, array $id): ?Node
    {
        return $this->nodes[self::key($class, $id)] ?? null;
    }

    /**
     * Drop an entity from identity tracking (after delete or detach).
     */
    public function detach(object $entity): void
    {
        $oid  = \spl_object_id($entity);
        $node = $this->oids[$oid] ?? null;

        if ($node === null) {
            return;
        }

        $key = self::key($node->class, $node->id);
        if (isset($this->nodes[$key]) && $this->nodes[$key] === $node) {
            unset($this->nodes[$key]);
        }
        if (
            ($this->slots[$oid] ?? null) !== null
                && ($this->nodes[$this->slots[$oid]] ?? null) === $node
        ) {
            unset($this->nodes[$this->slots[$oid]]);
        }
        unset($this->slots[$oid], $this->oids[$oid], $this->entities[$oid], $this->nodeOids[spl_object_id($node)]);
    }

    /**
     * All nodes currently scheduled for a flush, in insertion order.
     *
     * @return list<Node>
     */
    public function scheduled(): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            if ($node->isScheduled()) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Resolve the entity object a node was attached with (flush-time
     * backfill needs the actual instance, not just its bookkeeping node).
     * O(1) via the reverse node => oid index.
     */
    public function entityFor(Node $node): ?object
    {
        $oid = $this->nodeOids[spl_object_id($node)] ?? null;

        return $oid === null ? null : $this->entities[$oid];
    }

    /**
     * All nodes (any state), in insertion order.
     *
     * @return list<Node>
     */
    public function all(): array
    {
        return \array_values($this->nodes);
    }

    public function count(): int
    {
        return \count($this->nodes);
    }

    /**
     * Request-scoped hook: wipe the entire identity map. Called between
     * requests in persistent workers. This is a correctness requirement —
     * never turn the heap into a cross-request cache.
     */
    public function resetState(): void
    {
        $this->nodes    = [];
        $this->oids     = [];
        $this->entities = [];
        $this->nodeOids = [];
        $this->slots    = [];
    }
}