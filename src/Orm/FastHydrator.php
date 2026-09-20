<?php

namespace Azera\Orm;

use Azera\Orm\Casting\Cast;
use Azera\Orm\Casting\Casts;

/**
 * Per-class compiled hydration plan.
 *
 * The generic HydrationMap + RowSplitter path re-walks the metadata arrays
 * for EVERY row and entity: `foreach ($meta['columns'] as ...)` twice plus
 * per-field array_key_exists checks. This class compiles the same plan into
 * flat scalar arrays (field names, column aliases, PK aliases) ONCE per
 * class, so hydrating a row is: one heap lookup, one instantiation, two
 * tightly-typed copy loops over plain lists — no metadata array walking.
 *
 * Same contract as HydrationMap::build() + RowSplitter::split() for the
 * single-root (no relations) case, which is the hot path for list reads.
 * Relations keep using the generic path (they are per-row by nature).
 *
 * REFLECTION-FREE: every decision this class needs (column names, PK,
 * cast policy, nullability) is compiled into metadata by
 * Metadata::compile() — the ONE place properties are reflected. This
 * class only reshapes those arrays into paired lists.
 *
 * L1-cached per class like Metadata; nothing else to configure.
 */
final class FastHydrator
{
    /** @var array<class-string, self> */
    private static array $instances = [];

    public string $class;

    /** @var list<string> field names, aligned with $columns */
    public array $fields = [];

    /** @var list<string> raw column names, aligned with $fields */
    public array $columns = [];

    /** @var list<string> PK field names */
    public array $pkFields = [];

    /** @var list<string> raw column names for PK fields, aligned with $pkFields */
    public array $pkColumns = [];

    /**
     * Compiled decode plan: field POSITION -> Cast, for columns whose
     * RESOLVED cast policy applies ({@see Casts::forColumn} — the
     * metadata 'cast' flag: mongo's castExclusions or an explicit
     * #[Column(cast: false)] suppress the cast, #[Column(cast: true)]
     * forces it). Empty for the (common) cast-free class — hydrate()
     * keeps the plain tight loops with zero overhead. Built ONCE per
     * class from metadata (which already folded in the store's
     * castExclusions at compile time).
     *
     * @var array<int, Cast>
     */
    private array $decoders = [];

    /**
     * Compiled null policy: field POSITION -> the column's resolved
     * `nullable` flag. Every column gets an entry, so put() needs no
     * second lookup — and no SEPARATE gate flag, because the compiled
     * `nullable` IS the whole policy: Metadata::resolveNullable() rejects
     * the one shape a null could not be assigned for
     * (`T` + #[Column(nullable: true)]) at compile time, so
     * "nullable" implies "the property accepts null".
     *
     * @var array<int, bool>
     */
    private array $nullable = [];

    /**
     * Whether EVERY column is untyped + cast-free — arms hydrate()/
     * apply()'s raw fast path. An UNTYPED property (no PHP type
     * declaration) cannot be coerced by assignment, so the raw store
     * cell IS the property value AND the correct snapshot value; with
     * no casts either, the per-column put() gate (decode, read-back
     * snapshot) provably adds nothing but its call overhead. Typed or
     * casted columns — or any mix — keep the full put() path: a typed
     * property is assigned in WEAK mode (the driver's string coerces to
     * the declared type, so the snapshot must be read back off the
     * property), and a casted column must decode.
     *
     * The all-raw case is the common one: the plain legacy model shape
     * (untyped public properties) the competition's hot reads use.
     */
    private bool $allRaw;

    /** @var array<string, int> field name -> position in the paired lists */
    private array $fieldPos = [];

    private function __construct(string $class, array $meta)
    {
        $this->class = $class;
        $allRaw = true;

        foreach ($meta['columns'] as $field => $col) {
            $pos = \count($this->fields);
            $this->fields[]         = $field;
            $this->columns[]        = $col['name'];
            $this->fieldPos[$field] = $pos;
            $this->nullable[$pos]   = $col['nullable'];
            if ($col['pk']) {
                $this->pkFields[]  = $field;
                $this->pkColumns[] = $col['name'];
            }
            if (($cast = Casts::forColumn($col)) !== null) {
                $this->decoders[$pos] = $cast;
                $allRaw = false;
            } elseif ($col['typed']) {
                $allRaw = false;
            }
        }

        $this->allRaw = $allRaw;
    }

    /** Per-class singleton plan (mirrors Metadata::for semantics). */
    public static function for(string $class): self
    {
        $class = ltrim($class, '\\');
        if (isset(self::$instances[$class])) {
            return self::$instances[$class];
        }
        return self::$instances[$class] = new self($class, Metadata::for($class));
    }

    /**
     * Compile a row -> [entity, id, snapshotData] triple.
     *
     * Identity-map probe FIRST: with the shared request-scoped heap, the
     * same row read twice in one request MUST yield the same object (a
     * per-query heap never faced this because it died with the query).
     * A hit returns the existing instance untouched — the heap snapshot
     * stays authoritative and in-request mutations are not clobbered.
     *
     * $fresh=true inverts the hit behavior for STALE-READ-SENSITIVE reads:
     * the tracked instance is refreshed IN PLACE from the row ({@see apply()})
     * — same object, current values. Entities with scheduled (unflushed)
     * writes keep their pending state; the DB never clobbers queued work.
     *
     * Cold path: build id + entity + snapshot in three tight list loops,
     * attach once.
     *
     * @param array<string, mixed> $row raw assoc row keyed by COLUMN name
     * @return array{0: ?object, 1: array, 2: array}
     */
    public function hydrate(Heap $heap, array $row, bool $fresh = false): array
    {
        // PK identity from a list loop (scalar col names, no map walk).
        $pkCols = $this->pkColumns;
        $id     = [];
        $i      = 0;
        foreach ($pkCols as $col) {
            $value = $row[$col] ?? null;
            if ($value === null) {
                return [null, [], []]; // orphan guard
            }
            $id[$this->pkFields[$i++]] = $value;
        }

        // Identity hit: same PK in one request = same instance.
        $existing = $heap->findById($this->class, $id);
        if ($existing !== null) {
            $entity = $heap->entityFor($existing);
            if ($entity !== null) {
                // Fresh read: refresh the tracked instance IN PLACE from
                // the row — identity preserved, data current. Scheduled
                // (unflushed) writes are authoritative until flush();
                // never clobber them.
                if ($fresh && !$existing->isScheduled()) {
                    $this->apply($entity, $existing, $row);
                }
                return [$entity, $id, $existing->data];
            }
        }

        $entity = new ($this->class)();

        $fields = $this->fields;
        $cols   = $this->columns;

        // Fast path: EVERY column untyped + cast-free (the plain legacy
        // model — no PHP types, no casts). The raw cell is provably the
        // property value AND the snapshot value, so the copy loop reduces
        // to raw assignment with no put() call and no read-back. The NULL
        // contract is kept: the gate is one short-circuit comparison for
        // the non-null common case, and only an actual NULL pays the
        // nullable-flag lookup.
        if ($this->allRaw) {
            $data = [];
            for ($j = 0, $n = \count($fields); $j < $n; $j++) {
                $col = $cols[$j];
                if (array_key_exists($col, $row)) {
                    $value = $row[$col];
                    if ($value !== null || $this->nullable[$j]) {
                        $data[$col] = $entity->{$fields[$j]} = $value;
                    } else {
                        throw new \LogicException(
                            "Cannot hydrate NULL into {$this->class}::\${$fields[$j]}: the column is not nullable, but the store returned NULL."
                        );
                    }
                } else {
                    // Absent column: still needs a snapshot entry (null
                    // baseline) — same shape the put() path guarantees.
                    $data[$col] = isset($entity->{$fields[$j]}) ? $entity->{$fields[$j]} : null;
                }
            }

            $this->attach($heap, $entity, $id, $data);

            return [$entity, $id, $data];
        }

        // One pass, in paired-list order: decode + null gate + assign, and
        // record the value the property actually HOLDS as the snapshot.
        // Reading the snapshot back off the entity (rather than re-deriving
        // it from the raw cell) is what makes diff() correct by
        // construction — see put() for the #[Column(cast: false)] case
        // this fixes.
        $data   = [];

        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            $col = $cols[$j];
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $data[$col] = $this->put($entity, $fields[$j], $row[$col]);
        }

        // Columns absent from the row still get a snapshot entry (as null)
        // so diff() has a baseline for every tracked column.
        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            if (!array_key_exists($cols[$j], $data)) {
                $data[$cols[$j]] = isset($entity->{$fields[$j]}) ? $entity->{$fields[$j]} : null;
            }
        }

        $this->attach($heap, $entity, $id, $data);

        return [$entity, $id, $data];
    }

    /**
     * Refresh an EXISTING tracked entity in place from a fresh store row.
     *
     * Where the identity-map contract (one row = one object) meets the
     * freshness requirement: instead of materializing a second instance,
     * the row values are applied onto the live entity and the node
     * snapshot is updated to match — the new diff baseline. Values go
     * through the same {@see put()} gate as cold hydration, so the
     * refresh path cannot drift from the read path.
     *
     * Only columns present in $row are touched; entity and snapshot keep
     * their previous values for the rest (partial rows — explicit
     * columns() — stay consistent). Node state is NOT touched: callers
     * guarantee the entity is not scheduled.
     */
    public function apply(object $entity, Node $node, array $row): void
    {
        $fields = $this->fields;
        $cols   = $this->columns;

        // Fast path: every column untyped + cast-free — raw cell is both
        // the property value and the snapshot value (see hydrate()); the
        // NULL contract keeps its short-circuit gate.
        if ($this->allRaw) {
            for ($j = 0, $n = \count($fields); $j < $n; $j++) {
                $col = $cols[$j];
                if (!array_key_exists($col, $row)) {
                    continue;
                }
                $value = $row[$col];
                if ($value !== null || $this->nullable[$j]) {
                    $entity->{$fields[$j]} = $value;
                    $node->data[$col]      = $value;
                } else {
                    throw new \LogicException(
                        "Cannot hydrate NULL into {$this->class}::\${$fields[$j]}: the column is not nullable, but the store returned NULL."
                    );
                }
            }
            return;
        }

        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            $col = $cols[$j];
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $node->data[$col] = $this->put($entity, $fields[$j], $row[$col]);
        }
    }

    /**
     * Attach a hydrated entity to the heap as MANAGED.
     */
    public function attach(Heap $heap, object $entity, array $id, array $data): Node
    {
        $node = new Node($this->class, $id, $data, Node::MANAGED);
        $heap->attach($entity, $node);
        return $node;
    }

    /**
     * Decode one raw store value and put it on the entity, honoring the
     * compiled plan; returns the value the caller should record in the
     * node SNAPSHOT (the store representation).
     *
     * This is the ONE place store values become property values, used by
     * hydration AND every write-back path (fresh refresh, RETURNING rows,
     * id backfill, revert, joins) — the mirror of
     * EntityManager::extractData()'s single encode point. Centralizing it
     * is what keeps a snapshot diff-clean by construction: the snapshot is
     * read back OFF the property, so it always matches what extractData()
     * will produce for that entity. A `cast: false` typed column (PHP
     * coerces the driver's string on assignment) therefore cannot pick up
     * a phantom UPDATE, and a NULL cannot slip past the nullability
     * contract.
     *
     * The cast and the null gate come from THIS hydrator's compiled tables
     * (resolved once from metadata in the constructor), so callers pass no
     * metadata and this method never consults Metadata or reflection.
     *
     * NULL handling, from the compiled `nullable` flag ALONE:
     *
     * - column NOT nullable → throw a diagnosable LogicException. The raw
     *   TypeError this replaces fires at the assignment with no hint of
     *   which column, row, or remedy is at fault.
     * - column nullable → assign the null. Safe with no second check:
     *   resolveNullable() already rejected the one shape whose property
     *   could not hold it, so the flag implies the property accepts null.
     *
     * A non-null value is always assigned; PHP's weak mode coerces a
     * numeric string, and the cast has already decoded what needed it.
     */
    public function put(object $entity, string $field, mixed $raw): mixed
    {
        $pos   = $this->fieldPos[$field];
        $cast  = $this->decoders[$pos] ?? null;
        $value = $cast === null ? $raw : $cast->decode($raw);

        if ($value !== null) {
            $entity->{$field} = $value;
        } elseif ($this->nullable[$pos]) {
            $entity->{$field} = null;
        } else {
            throw new \LogicException(
                "Cannot hydrate NULL into {$this->class}::\${$field}: the column is not nullable, but the store returned NULL."
            );
        }

        // Snapshot value: read the property BACK (isset()-gated, so an
        // uninitialized typed property does not throw) and re-encode —
        // the exact value extractData() will produce for this entity.
        $stored = isset($entity->{$field}) ? $entity->{$field} : null;

        return $cast === null ? $stored : $cast->encode($stored);
    }

    /**
     * Forget all compiled plans (tests).
     */
    public static function clear(): void
    {
        self::$instances = [];
    }
}