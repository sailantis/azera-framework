<?php

namespace Azera\Orm;

/**
 * Executes a {@see HydrationMap} against flat rows.
 *
 * One row -> N entity instances (root + joined to-one relations), with
 * heap dedup (same PK = same object), LEFT JOIN orphan guard (all PK cols
 * NULL = no object), and to-many second queries executed on demand.
 *
 * All reads bypass ResultSet entirely: raw assoc rows in, entities out.
 */
final class RowSplitter
{
    public function __construct(
        private Heap $heap,
    ) {}

    /**
     * Hydrate root + joined to-one entities from one flat row.
     *
     * @param array $row      assoc row keyed by generated column aliases
     * @param array $plan     HydrationMap::build() output
     * @return array{0: ?object, 1: array<string, ?object>} root + by-relation
     */
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
            $obj   = $this->hydrateEntry($entry, $row);
            $related[$entry['relation']] = $obj;
        }

        return [$rootEntity, $related];
    }

    /**
     * Hydrate one entry from a flat row: orphan guard, heap dedup, field
     * copy via generated aliases.
     *
     * Values go through {@see FastHydrator::put()} — the same entry point
     * cold hydration uses — so joins get the column's cast, the compiled
     * null gate, and a snapshot read BACK off the entity. Bypassing it
     * (the pre-2026-09-19 behavior) skipped every cast AND left
     * driver-stringified values in the snapshot, which made the next
     * flush schedule a phantom UPDATE for every numeric column.
     *
     * The snapshot is keyed by RAW COLUMN name, matching the FastHydrator
     * path, so an entity loaded via a join diffs correctly against one
     * loaded by find() — the diff engine compares `extractData()` output,
     * which is column-name-keyed.
     */
    private function hydrateEntry(array $entry, array $row): ?object
    {
        $fields = $entry['fields'];
        $pk     = $entry['pk'];

        // Orphan guard: any PK column NULL -> no object (LEFT JOIN miss).
        $id = [];
        foreach ($entry['pk'] as $field => $colAlias) {
            $value = $row[$colAlias] ?? null;
            if ($value === null) {
                return null;
            }
            $id[$field] = $value;
        }

        // Heap dedup: same identity -> same object (identity map).
        $node = $this->heap->findById($entry['class'], $id);
        if ($node !== null) {
            return $this->heap->entityFor($node);
        }

        $put = FastHydrator::for($entry['class']);

        $entity = $this->instantiate($entry['class']);
        $data   = [];

        foreach ($fields as $field => [$colAlias, $colName]) {
            if (!array_key_exists($colAlias, $row)) {
                continue;
            }
            $data[$colName] = $put->put($entity, $field, $row[$colAlias]);
        }

        // Columns the joined row did not alias still need a snapshot entry.
        foreach ($fields as $field => [, $colName]) {
            if (!array_key_exists($colName, $data)) {
                $data[$colName] = isset($entity->{$field}) ? $entity->{$field} : null;
            }
        }

        $this->heap->attach($entity, new Node($entry['class'], $id, $data, Node::MANAGED));
        return $entity;
    }

    /**
     * Instance factory (kept simple for v1; constructor args/proxies later).
     */
    private function instantiate(string $class): object
    {
        return new $class();
    }
}