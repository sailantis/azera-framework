# Class: HydrationMap

**Full name:** [Azera\Orm\HydrationMap](../../src/Orm/HydrationMap.php)

Builds the hydration plan for a joined entity read.

The plan is a list of entries (root first, then one per requested to-one
relation) and a list of second-query specs for requested to-many
relations. Every entry maps FIELD names to generated SQL column aliases
({alias}__{column}), which is what makes one flat row hydratable into
several classes without collisions.

Pure function of (class, relation names) — cheap to rebuild, cacheable
later alongside metadata.

## Public methods

### build() · <small>[🗎](../../src/Orm/HydrationMap.php#L24)</small>

`public static function build(string $rootClass, array $relations): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$rootClass` | string | - |  |
| `$relations` | array | - | relation names from metadata |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
