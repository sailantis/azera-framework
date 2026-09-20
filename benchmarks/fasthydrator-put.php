<?php

declare(strict_types=1);

/**
 * Benchmark: uncommitted FastHydrator (put()-centralized) vs HEAD version.
 *
 * Arms (all in ONE process):
 *
 *   old  - FastHydrator as of HEAD (two copy loops, inline casts)
 *   new  - the ACTUAL \Azera\Orm\FastHydrator from the working tree
 *          (put()-centralized: one pass, decode + null gate + read-back
 *          snapshot). The put() centralization is a CORRECTNESS change
 *          (a `cast: false` typed column no longer picks up a phantom
 *          UPDATE); this harness prices it.
 *
 * Plain (no casts) and casted models measured separately; the refresh
 * (apply) path measured too. Rows are pgsql-style stringified ints.
 *
 * NOTE: this machine's measurement noise floor is +/-5-10% (see
 * temp/probe-signal-vs-noise.php for the same-object control that
 * proves it), so only differences well outside that band are meaningful.
 *
 * Run: php benchmarks/fasthydrator-put.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Azera\Orm\Attribute\Column;
use Azera\Orm\Heap;
use Azera\Orm\Model;
use Azera\Orm\Node;

/* --------------------------------------------------------------- fixtures */

class PlainBench extends Model
{
    public $id;
    public $title;
    public $body;
    public $author_id;
    public $views;
}

class CastedBench extends Model
{
    #[Column(type: 'int')]
    public $id;

    public $title;

    #[Column(type: 'int')]
    public $author_id;

    #[Column(type: 'int')]
    public $views;
}

/**
 * Mixed shape: typed props (fast path disarmed — weak-mode coercion
 * needs the read-back) with NO casts. The realistic modern-model case.
 */
class TypedBench extends Model
{
    public int $id;
    public string $title;
    public string $body;
    public int $author_id;
    public int $views;
}

/* -------------------------------------------------- shared plan compilation */

/**
 * Plan arrays compiled from real metadata (one per class), consumed by
 * the three arm implementations below - they differ ONLY in the loop
 * bodies, everything else (PK probing, heap attach) is identical.
 */
abstract class BenchHydrator
{
    public string $class;
    public array $fields = [];
    public array $columns = [];
    public array $pkFields = [];
    public array $pkColumns = [];
    public array $decoders = []; // pos => Cast
    public array $nullable = []; // pos => bool
    public array $fieldPos = []; // field => pos

    public function __construct(string $class)
    {
        $this->class = $class;
        foreach (\Azera\Orm\Metadata::for($class)['columns'] as $field => $col) {
            $pos = \count($this->fields);
            $this->fields[]         = $field;
            $this->columns[]        = $col['name'];
            $this->fieldPos[$field] = $pos;
            $this->nullable[$pos]   = $col['nullable'];
            if ($col['pk']) {
                $this->pkFields[]  = $field;
                $this->pkColumns[] = $col['name'];
            }
            if (($cast = \Azera\Orm\Casting\Casts::forColumn($col)) !== null) {
                $this->decoders[$pos] = $cast;
            }
        }
    }
}

/* --------------------------------------------------------------- arm: OLD (HEAD) */

final class OldHydrator extends BenchHydrator
{
    public function hydrate(Heap $heap, array $row): array
    {
        $id = [];
        $i  = 0;
        foreach ($this->pkColumns as $col) {
            $value = $row[$col] ?? null;
            if ($value === null) {
                return [null, [], []];
            }
            $id[$this->pkFields[$i++]] = $value;
        }

        $existing = $heap->findById($this->class, $id);
        if ($existing !== null) {
            $entity = $heap->entityFor($existing);
            if ($entity !== null) {
                return [$entity, $id, $existing->data];
            }
        }

        $entity = new ($this->class)();
        $fields = $this->fields;
        $cols   = $this->columns;
        if ($this->decoders === []) {
            for ($j = 0, $n = \count($fields); $j < $n; $j++) {
                if (array_key_exists($cols[$j], $row)) {
                    $entity->{$fields[$j]} = $row[$cols[$j]];
                }
            }
        } else {
            for ($j = 0, $n = \count($fields); $j < $n; $j++) {
                if (!array_key_exists($cols[$j], $row)) {
                    continue;
                }
                $value = $row[$cols[$j]];
                $cast  = $this->decoders[$j] ?? null;
                $entity->{$fields[$j]} = $cast === null ? $value : $cast->decode($value);
            }
        }

        $data = [];
        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            $value = $row[$cols[$j]] ?? null;
            $cast  = $this->decoders[$j] ?? null;
            $data[$cols[$j]] = $cast === null ? $value : $cast->encode($cast->decode($value));
        }

        $node = new Node($this->class, $id, $data, Node::MANAGED);
        $heap->attach($entity, $node);

        return [$entity, $id, $data];
    }

    public function apply(object $entity, Node $node, array $row): void
    {
        $fields = $this->fields;
        $cols   = $this->columns;

        if ($this->decoders === []) {
            for ($j = 0, $n = \count($fields); $j < $n; $j++) {
                $col = $cols[$j];
                if (array_key_exists($col, $row)) {
                    $entity->{$fields[$j]} = $row[$col];
                    $node->data[$col] = $row[$col];
                }
            }
            return;
        }

        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            $col = $cols[$j];
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $value = $row[$col];
            $cast  = $this->decoders[$j] ?? null;
            $entity->{$fields[$j]} = $cast === null ? $value : $cast->decode($value);
            $node->data[$col] = $cast === null ? $value : $cast->encode($cast->decode($value));
        }
    }
}

/* --------------------------------------------------------------- arm: NEW (working tree) */

final class NewHydrator extends BenchHydrator
{
    public function hydrate(Heap $heap, array $row): array
    {
        $id = [];
        $i  = 0;
        foreach ($this->pkColumns as $col) {
            $value = $row[$col] ?? null;
            if ($value === null) {
                return [null, [], []];
            }
            $id[$this->pkFields[$i++]] = $value;
        }

        $existing = $heap->findById($this->class, $id);
        if ($existing !== null) {
            $entity = $heap->entityFor($existing);
            if ($entity !== null) {
                return [$entity, $id, $existing->data];
            }
        }

        $entity = new ($this->class)();

        $data   = [];
        $fields = $this->fields;
        $cols   = $this->columns;

        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            $col = $cols[$j];
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $data[$col] = $this->put($entity, $fields[$j], $row[$col]);
        }

        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            if (!array_key_exists($cols[$j], $data)) {
                $data[$cols[$j]] = isset($entity->{$fields[$j]}) ? $entity->{$fields[$j]} : null;
            }
        }

        $node = new Node($this->class, $id, $data, Node::MANAGED);
        $heap->attach($entity, $node);

        return [$entity, $id, $data];
    }

    public function apply(object $entity, Node $node, array $row): void
    {
        $fields = $this->fields;
        $cols   = $this->columns;

        for ($j = 0, $n = \count($fields); $j < $n; $j++) {
            $col = $cols[$j];
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $node->data[$col] = $this->put($entity, $fields[$j], $row[$col]);
        }
    }

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
            throw new \LogicException("null into not-nullable");
        }

        $stored = isset($entity->{$field}) ? $entity->{$field} : null;

        return $cast === null ? $stored : $cast->encode($stored);
    }
}

/* --------------------------------------------------------------- arm: NEWOPT (candidate) */

/* ------------------------------------------------------------------- runner */

const ROWS   = 20000;
const ROUNDS = 7;
const WARMUP = 3000;

/**
 * @return array<string, float> ms per 1k rows per arm, interleaved:
 * round-robin arm order inside every round kills JIT/ordering bias.
 */
function measureAll(array $hydrators, array $rows, int $passes = 2): array
{
    $results = [];

    // warmup: JIT + plan
    foreach ($hydrators as $h) {
        $heap = new Heap();
        foreach (\array_slice($rows, 0, WARMUP) as $row) {
            $h->hydrate($heap, $row);
        }
    }

    for ($pass = 0; $pass < $passes; $pass++) {
        $times = [];
        for ($r = 0; $r < ROUNDS; $r++) {
            foreach ($hydrators as $label => $h) {
                $heap = new Heap();
                $t0   = hrtime(true);
                foreach ($rows as $row) {
                    $h->hydrate($heap, $row);
                }
                $times[$label][] = (hrtime(true) - $t0) / 1e6;
            }
        }
        foreach ($times as $label => $arr) {
            \sort($arr);
            $results[$pass][$label] = $arr[(int) (ROUNDS / 2)] / (ROWS / 1000);
        }
    }
    return $results;
}

/**
 * @return array{median: float} ms per 1k apply() calls
 */
function measureApply(object $h, int $n): array
{
    $heap = new Heap();
    $row  = ['id' => '1', 'title' => 'T', 'author_id' => '3', 'views' => '7'];
    [$entity] = $h->hydrate($heap, $row);
    $node = $heap->find($entity);
    $row2 = ['id' => '1', 'title' => 'T2', 'author_id' => '4', 'views' => '9'];

    for ($i = 0; $i < WARMUP; $i++) {
        $h->apply($entity, $node, $row2);
    }

    $times = [];
    for ($r = 0; $r < ROUNDS; $r++) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $h->apply($entity, $node, $row2);
        }
        $times[] = (hrtime(true) - $t0) / 1e6;
    }
    \sort($times);

    return ['median' => $times[(int) (ROUNDS / 2)] / ($n / 1000)];
}

function rowsFor(string $class, int $n): array
{
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        if ($class === CastedBench::class || $class === TypedBench::class) {
            $rows[] = ['id' => (string) $i, 'title' => "Title {$i}", 'author_id' => (string) ($i % 50), 'views' => (string) ($i % 1000)];
        } else {
            $rows[] = ['id' => (string) $i, 'title' => "Title {$i}", 'body' => "Body text {$i}", 'author_id' => (string) ($i % 50), 'views' => (string) ($i % 1000)];
        }
    }
    return $rows;
}

printf("PHP %s | opcache+JIT | %d rows x %d rounds\n\n", PHP_VERSION, ROWS, ROUNDS);

$plainRows  = rowsFor(PlainBench::class, ROWS);
$castedRows = rowsFor(CastedBench::class, ROWS);
$typedRows  = rowsFor(TypedBench::class, ROWS);

$shapes = [
    ['plain untyped, no casts (allRaw ON)', PlainBench::class, $plainRows],
    ['typed props, no casts (allRaw OFF)', TypedBench::class, $typedRows],
    ['untyped props + int casts (allRaw OFF)', CastedBench::class, $castedRows],
];

foreach ($shapes as [$label, $class, $rows]) {
    echo "=== hydrate(): {$label} ===\n";

    $hydrators = [
        'old (HEAD)'            => new OldHydrator($class),
        'new put() only'        => new NewHydrator($class),
        // The REAL working-tree FastHydrator — carries the typed-flag
        // fast path implemented 2026-09-20, not a stale hand copy.
        'real (put+fast path)'  => \Azera\Orm\FastHydrator::for($class),
    ];

    foreach (measureAll($hydrators, $rows) as $pass => $arms) {
        echo $pass === 0 ? "pass 1 (warm-up bias possible)\n" : "pass 2 (fully warm)\n";
        foreach ($arms as $name => $ms) {
            printf("%-26s %8.3f ms / 1k rows\n", $name, $ms);
        }
    }
    echo "\n";
}

echo "=== apply()/refresh (casted, same entity) ===\n";
$applyHydrators = [
    'old (HEAD)'            => new OldHydrator(CastedBench::class),
    'new put() only'        => new NewHydrator(CastedBench::class),
    'real (put+fast path)'  => \Azera\Orm\FastHydrator::for(CastedBench::class),
];
foreach ($applyHydrators as $name => $h) {
    printf("%-26s %8.3f ms / 1k apply()s\n", $name, measureApply($h, ROWS)['median']);
}