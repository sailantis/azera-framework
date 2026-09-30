# Class: ChainResolver

**Full name:** [Azera\Db\Resolver\ChainResolver](../../src/Db/Resolver/ChainResolver.php)

Tries each resolver in order and returns the first successful result.

If none of the chained resolvers can resolve the name, a
[`ResolveException`](Db_Resolver_ResolveException.md) is thrown. This preserves typo detection —
an unknown name like `Userr` that matches no model class and no mapping
entry will throw rather than silently fall back to a literal table.

Typically used to combine a [`ModelResolver`](Db_Resolver_ModelResolver.md) and a
[`MappingResolver`](Db_Resolver_MappingResolver.md) as the AppContext default:

```php
new ChainResolver(new ModelResolver(), new MappingResolver($mapping))
```

## Public methods

### __construct() · <small>[🗎](../../src/Db/Resolver/ChainResolver.php#L27)</small>

`public function __construct(array $resolvers): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$resolvers` | array | - | Resolvers to try, in order. |

**Return value**

- Type: `mixed`


---

### resolve() · <small>[🗎](../../src/Db/Resolver/ChainResolver.php#L31)</small>

`public function resolve(string $name): array`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string | - |  |

**Return value**

- Type: `array`



---

[Back to the Index ⤴](README.md)
