<?php

declare(strict_types=1);

/**
 * Benchmark: uncommitted RowSplitter (put() gate, column-keyed snapshot)
 * vs HEAD version (raw assignment, field-keyed snapshot).
 *
 * NOTE the HEAD version is measurably WRONG as well as faster: it
 * skipped every cast and keyed the node snapshot by FIELD name, so a
 * joined entity's next flush scheduled a phantom UPDATE for every
 * numeric column. This harness quantifies what the correctness fix
 * costs on the join-read hot path.
 *
 * Run: php benchmarks/rowsplitter-put.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Azera\Orm\Attribute\BelongsTo;
use Azera\Orm\Attribute\Column;
use Azera\Orm\Heap;
use Azera\Orm\Model;
use Azera\Orm\Node;

class SplitArticle extends Model
{
    #[Column(type: 'int')]
    public $id;

    public $title;

    #[Column(type: 'int')]
    public $author_id;

    #[BelongsTo(target: SplitAuthor::class)]
    public ?SplitAuthor $author;
}

class SplitAuthor extends Model
{
    #[Column(type: 'int')]
    public $id;

    public $name;

    #[Column(type: 'int')]
    public $rating;
}

const ROWS   = 20000;
const ROUNDS = 7;
const WARMUP = 3000;

/* ------------------------------------------------------------ plan builder */

/**
 * Current HydrationMap::build() shape (fields => [alias, colName]).
 */
function buildPlanNew(): array
{
    return \Azera\Orm\HydrationMap::build(SplitArticle::class, ['author']);
}

/**
 * HEAD shape derived from the NEW plan: same entries, same aliases, but
 * fields => alias (scalar) — identical row keys for both arms.
 */
function buildPlanOld(array $planNew): array
{
    $entries = [];
    foreach ($planNew['entries'] as $entry) {
        $entries[] = [
            'class'    => $entry['class'],
            'alias'    => $entry['alias'],
            'relation' => $entry['relation'] ?? null,
            'fields'   => \array_map(fn($pair) => $pair[0], $entry['fields']),
            'pk'       => $entry['pk'],
        ];
    }
    return ['entries' => $entries];
}

/* ------------------------------------------------------ old splitter (HEAD) */

final class OldSplitter
{
    public function __construct(private Heap $heap) {}

    public function split(array $row, array $plan): array
    {
        $entries = $plan['entries'];
        $root    = $entries[0];

        $rootEntity = $this->hydrateEntry($root, $row);
        if ($rootEntity === null) {
            return [null, []];
        }

        $related = [];
        for ($i = 1, $n = \count($entries); $i < $n; $i++) {
            $entry = $entries[$i];
            $related[] = $this->hydrateEntry($entry, $row);
        }

        return [$rootEntity, $related];
    }

    private function hydrateEntry(array $entry, array $row): ?object
    {
        $fields = $entry['fields'];
        $pk     = $entry['pk'];

        $id = [];
        foreach ($pk as $field => $colAlias) {
            $value = $row[$colAlias] ?? null;
            if ($value === null) {
                return null;
            }
            $id[$field] = $value;
        }

        $node = $this->heap->findById($entry['class'], $id);
        if ($node !== null) {
            return $this->heap->entityFor($node);
        }

        $entity = new ($entry['class'])();
        foreach ($fields as $field => $colAlias) {
            if (array_key_exists($colAlias, $row)) {
                $entity->{$field} = $row[$colAlias];
            }
        }

        $data = [];
        foreach ($entry['fields'] as $field => $colAlias) {
            $data[$field] = $row[$colAlias] ?? null;
        }

        $this->heap->attach($entity, new Node($entry['class'], $id, $data, Node::MANAGED));
        return $entity;
    }
}

/* ------------------------------------------------------------------- rows */

/**
 * One flat join row PER the NEW plan's actual aliases — the row keys MUST
 * match the plan or the orphan guard silently no-ops every hydrateEntry
 * (a measurement of nothing). Sanity-checked by the runner.
 */
function joinRows(int $n, array $plan): array
{
    $root = $plan['entries'][0];
    $rel  = $plan['entries'][1];

    $rootPkAlias = \reset($root['pk']);
    $relPkAlias  = \reset($rel['pk']);
    $rootCols    = [];
    foreach ($root['fields'] as [$alias]) {
        $rootCols[] = $alias;
    }
    $relCols = [];
    foreach ($rel['fields'] as [$alias]) {
        $relCols[] = $alias;
    }

    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $a   = $i % 500; // 500 distinct authors -> real dedup work
        $row = [];
        foreach ($rootCols as $c) {
            $row[$c] = (string) (\str_contains($c, 'id') ? ($c === $rootPkAlias ? $i : $i % 500) : "Title {$i}");
        }
        foreach ($relCols as $c) {
            $row[$c] = (string) ($c === $relPkAlias ? $a : (\str_contains($c, 'rating') ? $a % 5 : "Author {$a}"));
        }
        $rows[] = $row;
    }
    return $rows;
}

function bench(callable $split, array $plan, array $rows): float
{
    for ($i = 0; $i < WARMUP; $i++) {
        $split(new Heap(), $rows[$i], $plan);
    }

    $times = [];
    for ($r = 0; $r < ROUNDS; $r++) {
        $heap = new Heap();
        $t0   = hrtime(true);
        foreach ($rows as $row) {
            $split($heap, $row, $plan);
        }
        $times[] = (hrtime(true) - $t0) / 1e6;
    }
    \sort($times);

    return $times[(int) (ROUNDS / 2)] / (ROWS / 1000);
}

printf("PHP %s | %d join rows x %d rounds\n\n", PHP_VERSION, ROWS, ROUNDS);

$planNew = buildPlanNew();
$rows    = joinRows(ROWS, $planNew);
$planOld = buildPlanOld($planNew);

// WORK SANITY: both arms must actually hydrate N roots + 500 authors.
foreach ([$planNew, $planOld] as $plan) {
    $heap     = new Heap();
    $hydrated = 0;
    foreach (\array_slice($rows, 0, 1000) as $row) {
        $s = new \Azera\Orm\RowSplitter($heap);
        [$root, $related] = $s->split($row, $plan);
        if ($root !== null) {
            $hydrated++;
        }
    }
    if ($hydrated !== 1000) {
        \fwrite(STDERR, "SANITY FAIL: only {$hydrated}/1000 roots hydrated - row keys do not match the plan\n");
        exit(1);
    }
}
echo "sanity: 1000/1000 roots hydrated by BOTH arms\n\n";

$newSplitter = fn(Heap $heap) => new \Azera\Orm\RowSplitter($heap);
$oldSplitter = fn(Heap $heap) => new OldSplitter($heap);

$arms = [
    'old (HEAD, cast-bypassing)' => fn(Heap $heap, array $row, array $plan) =>
        $oldSplitter($heap)->split($row, $plan),
    'new (uncommitted, put())' => fn(Heap $heap, array $row, array $plan) =>
        $newSplitter($heap)->split($row, $plan),
];

// Two PASSES, alternating arms old->new->old->new, so any first-touch/JIT
// warm-up bias lands on BOTH arms equally; report pass 2 (fully warm).
$passes = [];
for ($pass = 0; $pass < 2; $pass++) {
    foreach ($arms as $label => $split) {
        $passes[$pass][$label] = bench($split, $label[0] === 'o' ? $planOld : $planNew, $rows);
    }
}

foreach ($passes as $i => $pass) {
    echo $i === 0 ? "pass 1 (warm-up bias possible)\n" : "pass 2 (fully warm)\n";
    foreach ($pass as $label => $ms) {
        printf("%-34s %8.3f ms / 1k join rows\n", $label, $ms);
    }
    echo "\n";
}