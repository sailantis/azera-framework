<?php

namespace Azera\Orm;

/**
 * Builds the hydration plan for a joined entity read.
 *
 * The plan is a list of entries (root first, then one per requested to-one
 * relation) and a list of second-query specs for requested to-many
 * relations. Every entry maps FIELD names to generated SQL column aliases
 * ({alias}__{column}), which is what makes one flat row hydratable into
 * several classes without collisions.
 *
 * Pure function of (class, relation names) — cheap to rebuild, cacheable
 * later alongside metadata.
 */
final class HydrationMap
{
    /**
     * @param class-string   $rootClass
     * @param list<string>   $relations relation names from metadata
     * @return array{entries: list<array>, secondQueries: list<array>}
     */
    public static function build(string $rootClass, array $relations): array
    {
        $rootMeta = Metadata::for($rootClass);
        $root     = self::entry($rootClass, $rootMeta['source'], null, null, null);

        $entries       = [$root];
        $secondQueries = [];

        foreach ($relations as $name) {
            $rel = $rootMeta['relations'][$name]
                ?? throw new \InvalidArgumentException(
                    "Unknown relation '{$name}' on {$rootClass}"
                );

            if ($rel['type'] === 'hasMany') {
                $secondQueries[] = [
                    'relation'   => $name,
                    'class'      => $rel['target'],
                    'foreignKey' => $rel['foreignKey'],
                    'ownerKey'   => $rel['ownerKey'],
                ];
                continue;
            }

            // to-one: BelongsTo or HasOne -> LEFT JOIN
            $parentAlias = $root['alias'];
            $childAlias  = $parentAlias . '_' . $name;

            $entry = self::entry($rel['target'], $childAlias, $name, 0);

            // Join condition generation from metadata (no manual strings).
            if ($rel['type'] === 'belongsTo') {
                // parent holds the FK
                $entry['joinOn'] = [
                    'left'  => $root['alias'] . '.' . $rel['foreignKey'],
                    'right' => $childAlias . '.' . $rel['ownerKey'],
                ];
            } else {
                // hasOne: target table holds the FK back to the parent
                $entry['joinOn'] = [
                    'left'  => $childAlias . '.' . $rel['foreignKey'],
                    'right' => $root['alias'] . '.' . $rel['ownerKey'],
                ];
            }

            $entries[] = $entry;
        }

        return ['entries' => $entries, 'secondQueries' => $secondQueries];
    }

    /**
     * @return array{class: class-string, alias: string, relation: ?string,
     *               parent: ?int, joinOn: ?array, pk: array, fields: array}
     */
    private static function entry(
        string $class,
        string $alias,
        ?string $relation,
        ?int $parent,
        ?array $joinOn = null,
    ): array {
        $meta   = Metadata::for($class);
        $fields = [];
        $pk     = [];

        foreach ($meta['columns'] as $field => $col) {
            $colAlias = $alias . '__' . $col['name'];
            // field -> [generated alias, raw column name]. The raw name is
            // what a heap snapshot is keyed by, so the splitter needs it to
            // keep snapshots identical to the FastHydrator path.
            $fields[$field] = [$colAlias, $col['name']];
            if ($col['pk']) {
                $pk[$field] = $colAlias;
            }
        }

        return [
            'class'    => $class,
            'alias'    => $alias,
            'relation' => $relation,
            'parent'   => $parent,
            'joinOn'   => $joinOn,
            'pk'       => $pk,
            'fields'   => $fields,
        ];
    }
}