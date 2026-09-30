# Class: ModelResolver

**Full name:** [Azera\Db\Resolver\ModelResolver](../../src/Db/Resolver/ModelResolver.php)

Resolves logical names as fully-qualified model class names.

If the name is an existing class that extends [`Model`](Orm_Model.md), the resolver
instantiates it (cached per class) and returns the model's `source()`,
`schema()`, read/write roles, and `idFields()`. This enables `FETCH_CLASS`
hydration via the returned `modelClass`.

If the name is not a valid model class, a [`ResolveException`](Db_Resolver_ResolveException.md) is thrown.
This preserves typo detection — `table('Userr')` throws rather than silently
falling back to a literal table.

The model instance cache is an instance property. When the `ModelResolver`
is registered as a singleton in `AppContext`, the cache persists across
RoadRunner requests. This is safe because `Model` instances are stateless
with respect to connections — `readConnection()`/`writeConnection()` resolve
fresh via `AppContext::dbManager()` on every call. Implementing
[`RequestScoped`](Lifecycle_RequestScoped.md) ensures the cache is cleared after every request in a
persistent worker, so no state leaks from one request into the next.

## Public methods

### resolve() · <small>[🗎](../../src/Db/Resolver/ModelResolver.php#L35)</small>

`public function resolve(string $name): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `array`


---

### clearCache() · <small>[🗎](../../src/Db/Resolver/ModelResolver.php#L52)</small>

`public function clearCache(): void`

Clear the model instance cache (useful for testing).

**Return value**

- Type: `void`


---

### resetState() · <small>[🗎](../../src/Db/Resolver/ModelResolver.php#L61)</small>

`public function resetState(): void`

Request-scoped hook: clear the model instance cache after each request
in a persistent worker so no state leaks into the next request.

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
