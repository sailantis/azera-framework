# Class: Entity

**Full name:** [Azera\Orm\Attribute\Entity](../../src/Orm/Attribute/Entity.php)

Class-level persistence configuration — the ONE routing/naming attribute.

Declares which store type handles the class and where its data lives
(table/collection name + schema), declaratively instead of overriding
source()/schema(). Compiled into metadata, so the Store seam, the query
builder (via ModelResolver) and the model facade defaults all see it
with zero runtime cost.

Store routing: the `store` argument is the registry key the EntityManager
resolves — `$em->setStore('mongo', new MongoStore(...))` +
`#[Entity(store: 'mongo')]`. A connection-owning backend served under
multiple clients registers one store type per client ('mongo-eu',
'mongo-us'); the type name IS the discriminator (there is no role axis).

Connection roles stay with #[Connection] (readRole/writeRole metadata),
consumed by borrowing stores (PdoStore) per class.

Precedence: a source()/schema() override on the model still wins over
the attribute (dynamic > static); the attribute wins over the naming
convention.

## Public Properties

- `public` string|null `$name` · <small>[🗎](../../src/Orm/Attribute/Entity.php)</small>
- `public` string|null `$schema` · <small>[🗎](../../src/Orm/Attribute/Entity.php)</small>
- `public` string|null `$store` · <small>[🗎](../../src/Orm/Attribute/Entity.php)</small>

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Attribute/Entity.php#L30)</small>

`public function __construct(string|null $name = null, string|null $schema = null, string|null $store = null): mixed`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$name` | string\|null | `null` |  |
| `$schema` | string\|null | `null` |  |
| `$store` | string\|null | `null` |  |

**Return value**

- Type: `mixed`



---

[Back to the Index ⤴](README.md)
