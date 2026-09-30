# Interface: TableResolver

**Full name:** [Azera\Db\Resolver\TableResolver](../../src/Db/Resolver/TableResolver.php)

Resolves a logical model/table name into a concrete source descriptor.

This is the single seam between the query builder and the data layer.
Implementations decide what a name like `User` or `users` means —
whether it maps to a real model class, a virtual mapping entry, or
a literal table name.

The resolver is also the single source of truth for hydration: when
`modelClass` is non-null, the query builder passes it to `ResultSet`
so that `FETCH_CLASS` hydration is available. When it is null, only
fast array/object fetching is available.

## Public methods

### resolve() · <small>[🗎](../../src/Db/Resolver/TableResolver.php#L36)</small>

`public function resolve(string $name): array`

Resolve a logical name to a concrete source descriptor.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - | The logical model/table name (e.g. `User`, `users`, `App\Models\Order`). |

**Return value**

- Type: `array`
- Description: <br>- `source`:     The concrete table or view name.<br>- `schema`:     Optional database schema (e.g. for PostgreSQL), or null.<br>- `read`:       Optional read connection role, or null (falls back to AppContext default).<br>- `write`:      Optional write connection role, or null (falls back to AppContext default).<br>- `modelClass`: The fully-qualified model class name when this name maps to a real model,<br>                or null for mapping/literal resolvers (no hydration).<br>- `idFields`:   The primary key field names (for UPSERT conflict target), or null.

**Throws**

- [ResolveException](Db_Resolver_ResolveException.md)  When the name cannot be resolved.



---

[Back to the Index ⤴](README.md)
