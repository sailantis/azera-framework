# Class: LiteralResolver

**Full name:** [Azera\Db\Resolver\LiteralResolver](../../src/Db/Resolver/LiteralResolver.php)

Resolves names as literal table names with no model hydration.

This is the resolver used by [`Query::raw()`](Db_Query.md#raw).
Every name is treated as-is — no class lookups, no mapping lookups,
no connection overrides, no hydration.

## Public methods

### resolve() · <small>[🗎](../../src/Db/Resolver/LiteralResolver.php#L16)</small>

`public function resolve(string $name): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
