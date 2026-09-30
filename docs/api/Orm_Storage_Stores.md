# Class: Stores

**Full name:** [Azera\Orm\Storage\Stores](../../src/Orm/Storage/Stores.php)

Context-attached store map: type name => Store instance. StoreManager's
replacement after the collapse — deliberately DUMB: no factories, no
roles, no default-role machinery. The type name is the single
discriminator (a connection-owning backend with two clients registers
two types: 'mongo-eu', 'mongo-us').

WHY A HOLDER INSTEAD OF A PLAIN EM PROPERTY: Metadata::doCompile() is
STATIC and consults the registered store at compile time (enrichment —
e.g. pkMode must be known while PKs resolve). Reaching through
AppContext::instance() is the established static path; reaching for the
EM instance mid-compile would risk recursive construction.

WHY NOT A STATIC ARRAY ON THE EM: tests isolate via a fresh AppContext
per test (setInstance/reset) — a process-global static would bleed
stores across tests into freshly compiled (and L2-cached) metadata.
A context-attached holder inherits that isolation for free.

Registered in the context under this class name; EntityManager::setStore()
writes through it, storeFor()/Metadata read through it. Pure
configuration, no per-request state — deliberately NOT RequestScoped.

## Public methods

### __construct() · <small>[🗎](../../src/Orm/Storage/Stores.php#L32)</small>

`public function __construct(): mixed`

**Return value**

- Type: `mixed`


---

### set() · <small>[🗎](../../src/Orm/Storage/Stores.php#L39)</small>

`public function set(string $type, Azera\Orm\Storage\Store $store): void`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |
| `$store` | [Store](Orm_Storage_Store.md) | - |  |

**Return value**

- Type: `void`


---

### has() · <small>[🗎](../../src/Orm/Storage/Stores.php#L44)</small>

`public function has(string $type): bool`

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |

**Return value**

- Type: `bool`


---

### tryGet() · <small>[🗎](../../src/Orm/Storage/Stores.php#L54)</small>

`public function tryGet(string $type): Azera\Orm\Storage\Store|null`

Lenient NON-throwing lookup: null when the type is unregistered.

Hot paths treat "nothing registered" as ordinary control flow
(fallback resolution) — a miss costs one array probe.

**Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$type` | string | - |  |

**Return value**

- Type: [Store](Orm_Storage_Store.md)|`null`



---

[Back to the Index ⤴](README.md)
