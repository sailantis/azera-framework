# 🧩 Class: Metadata

**Full name:** [Azera\Orm\Metadata](../../src/Orm/Metadata.php)

Compiles class attributes into a plain metadata array, ONCE per class.

Compiled shape (all values JSON-serializable — required for the L2 cache):
```
[
  'class'      => class-string,
  'source'     => string,              // #[Entity(name)] > source() override > convention
  'schema'     => ?string,             // #[Entity(schema)] > schema() override > null
  'store'      => string,              // #[Entity(store)] — default 'sql' (the zero-config path)
  'pkMode'     => string,              // 'model-chain' | 'convention' — resolved by the store (enrichment)
  'readRole'   => ?string,             // #[Connection(read|role)] — null = unset
  'writeRole'  => ?string,             // #[Connection(write|role)] — null = unset
  'pkFields'   => list<string>,        // resolved PK fields, declaration order (['id'] fallback)
  'castExclusions' => list<string>,    // types whose cast the STORE suppresses by default
                                       // (store-contributed during enrichment; absent = cast all)
  'columns'    => [name => ['name' =>.., 'type' =>.., 'nullable' => bool,
                                      'typed' => bool, 'pk'                                            => bool, 'cast'                                          => bool]],
                                       // 'cast': resolved AUTO/FORCE/SUPPRESS decision (#[Column(cast:)
                                       //   vs the store's castExclusions) — the ONE cast authority
                                       //   every write/read site consults
                                       // 'nullable': column nullability, from ?type OR #[Column(nullable:)]
                                       //   — and the COMPLETE hydration policy for the column: a
                                       //   store NULL into a nullable column is always assignable
                                       //   (resolveNullable upholds "nullable ⇒ the property accepts
                                       //   null"), so no second gate flag is compiled
                                       // 'typed': whether the property carries a PHP type declaration —
                                       //   FastHydrator's all-raw fast path reads it: an untyped
                                       //   property cannot be coerced on assignment, so the raw
                                       //   store cell IS the snapshot value (no put() read-back)
  'relations'  => [name => ['type'=>.., 'target'=>.., 'foreignKey'=>.., 'ownerKey'=>.., 'strategy' => 'join'|'second_query']],
]
```

Store routing is GENERIC: metadata `store` is an opaque registry key —
EntityManager::setStore('name', $store) maps it to an instance. Core
never learns backend names; backend-specific metadata (e.g. pkMode) is
contributed by the store itself during compile (`MetadataContributor`).

PK resolution (pkMode): 'model-chain' (SQL default) = a declared
idFields() override is the authority; without one, explicit #[Column(pk:)]
marks define the key (all-or-nothing — one explicit mark disables the
implicit default), falling back to ['id']. 'convention' (documents) keeps
the id/*_id name convention, with #[Column(pk:)] marks layered on top.
Which mode applies is decided by the class's STORE (a mongo document may
be a Model subclass — only the store knows its PK semantics).

Attribute validation: #[Connection] is store-agnostic in CORE (any store
may honor per-class connection roles); each STORE validates during
enrichment which attributes it can honor (e.g. MongoStore rejects
#[Connection] — it owns its clients).

Caching (two tiers):
- L1: per-process static array (survives across RoadRunner requests).
- L2: OPT-IN second tier. `useCache()` accepts any PSR-16
  CacheInterface — e.g. APCu (azera-cache ApcuCache), Redis, File —
  for setups where L1 dies with the request (PHP-FPM). Default is NO
  L2: metadata compiles per process, which is always correct.

  Invalidating L2 is the USER's responsibility once a backend is
  wired: use `cacheSalt()` (e.g. a deploy hash, in bootstrap),
  a TTL via useCache(), clear() on deploy, or call
  Metadata::clear() from an admin route. The VERSION constant
  remains the framework-side escape hatch when the compiled shape
  changes.

Compile-time vs runtime: source/schema/idFields are resolved ONCE here
(a declared override is consulted during compilation). Only the runtime
connection-role setters (setDefaultReadRole/...) remain per-request
dynamic — they sit ABOVE the #[Connection] attribute in precedence.

## 🚀 Public methods

### useCache() · [source](../../src/Orm/Metadata.php#L128)

`public static function useCache(Psr\SimpleCache\CacheInterface|null $cache, int|null $ttl = null): void`

Wire a PSR-16 backend as the L2 metadata cache (e.g. APCu, Redis,
File from azera-cache, or any PSR-16 implementation). Pass null to
disable L2 again.

Invalidating the shared store is then the application's job —
typically `cacheSalt()` with a deploy hash, or a TTL:

    Metadata::useCache(new ApcuCache(), ttl: 86400);
    Metadata::cacheSalt(ENV['DEPLOY_HASH']);

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$cache` | Psr\SimpleCache\CacheInterface\|null | - | backend or null to disable |
| `$ttl` | int\|null | `null` | seconds for stored entries (null = backend default) |

**➡️ Return value**

- Type: void


---

### cacheSalt() · [source](../../src/Orm/Metadata.php#L140)

`public static function cacheSalt(string|null $salt): void`

Set an optional salt mixed into the L2 cache key. Change it (e.g.

per deploy: a build hash, git SHA, config version) to force a
full recompile of every model — the old keys are simply never
requested again (TTL or backend eviction reclaims their space).

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$salt` | string\|null | - |  |

**➡️ Return value**

- Type: void


---

### for() · [source](../../src/Orm/Metadata.php#L150)

`public static function for(string $class): array`

Compile (or fetch from cache) metadata for a class.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |

**➡️ Return value**

- Type: array


---

### clear() · [source](../../src/Orm/Metadata.php#L171)

`public static function clear(): void`

Forget all cached metadata (tests, deploys, admin routes).

L1 and the compiling guard are always reset. With a PSR-16 backend
wired, only THIS component's keys are deleted (tracked in a small
index entry) — never the whole shared cache segment, which the
application may be using for unrelated data.

**➡️ Return value**

- Type: void


---

### clearL1() · [source](../../src/Orm/Metadata.php#L190)

`public static function clearL1(): void`

Forget only the L1 (per-process) tier — any wired L2 stays warm.

**➡️ Return value**

- Type: void


---

### isCompiling() · [source](../../src/Orm/Metadata.php#L202)

`public static function isCompiling(string $class): bool`

True while the class's metadata is being compiled. Model's
metadata-backed accessors check this and fall back to the raw
convention, so an override calling parent::source()/idFields()
during compilation cannot recurse into compile() again.

**🧭 Parameters**

| Name | Type | Default | Description |
|---|---|---|---|
| `$class` | string | - |  |

**➡️ Return value**

- Type: bool



---

[Back to the Index ⤴](README.md)
