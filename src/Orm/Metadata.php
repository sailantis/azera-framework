<?php

namespace Azera\Orm;

use Azera\Orm\Attribute\BelongsTo;
use Azera\Orm\Attribute\Column;
use Azera\Orm\Attribute\Connection;
use Azera\Orm\Attribute\Entity;
use Azera\Orm\Attribute\HasMany;
use Azera\Orm\Attribute\HasOne;
use Azera\Db\ModelMapping;
use Azera\Orm\Storage\MetadataContributor;
use Azera\Orm\Storage\Stores;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Compiles class attributes into a plain metadata array, ONCE per class.
 *
 * Compiled shape (all values JSON-serializable — required for the L2 cache):
 * ```
 * [
 *   'class'      => class-string,
 *   'source'     => string,              // #[Entity(name)] > source() override > convention
 *   'schema'     => ?string,             // #[Entity(schema)] > schema() override > null
 *   'store'      => string,              // #[Entity(store)] — default 'sql' (the zero-config path)
 *   'pkMode'     => string,              // 'model-chain' | 'convention' — resolved by the store (enrichment)
 *   'readRole'   => ?string,             // #[Connection(read|role)] — null = unset
 *   'writeRole'  => ?string,             // #[Connection(write|role)] — null = unset
 *   'pkFields'   => list<string>,        // resolved PK fields, declaration order (['id'] fallback)
 *   'castExclusions' => list<string>,    // types whose cast the STORE suppresses by default
 *                                        // (store-contributed during enrichment; absent = cast all)
 *   'columns'    => [name => ['name' =>.., 'type' =>.., 'nullable' => bool,
 *                                       'typed' => bool, 'pk'                                            => bool, 'cast'                                          => bool]],
 *                                        // 'cast': resolved AUTO/FORCE/SUPPRESS decision (#[Column(cast:)
 *                                        //   vs the store's castExclusions) — the ONE cast authority
 *                                        //   every write/read site consults
 *                                        // 'nullable': column nullability, from ?type OR #[Column(nullable:)]
 *                                        //   — and the COMPLETE hydration policy for the column: a
 *                                        //   store NULL into a nullable column is always assignable
 *                                        //   (resolveNullable upholds "nullable ⇒ the property accepts
 *                                        //   null"), so no second gate flag is compiled
 *                                        // 'typed': whether the property carries a PHP type declaration —
 *                                        //   FastHydrator's all-raw fast path reads it: an untyped
 *                                        //   property cannot be coerced on assignment, so the raw
 *                                        //   store cell IS the snapshot value (no put() read-back)
 *   'relations'  => [name => ['type'=>.., 'target'=>.., 'foreignKey'=>.., 'ownerKey'=>.., 'strategy' => 'join'|'second_query']],
 * ]
 * ```
 *
 * Store routing is GENERIC: metadata `store` is an opaque registry key —
 * EntityManager::setStore('name', $store) maps it to an instance. Core
 * never learns backend names; backend-specific metadata (e.g. pkMode) is
 * contributed by the store itself during compile ({@see MetadataContributor}).
 *
 * PK resolution (pkMode): 'model-chain' (SQL default) = a declared
 * idFields() override is the authority; without one, explicit #[Column(pk:)]
 * marks define the key (all-or-nothing — one explicit mark disables the
 * implicit default), falling back to ['id']. 'convention' (documents) keeps
 * the id/*_id name convention, with #[Column(pk:)] marks layered on top.
 * Which mode applies is decided by the class's STORE (a mongo document may
 * be a Model subclass — only the store knows its PK semantics).
 *
 * Attribute validation: #[Connection] is store-agnostic in CORE (any store
 * may honor per-class connection roles); each STORE validates during
 * enrichment which attributes it can honor (e.g. MongoStore rejects
 * #[Connection] — it owns its clients).
 *
 * Caching (two tiers):
 * - L1: per-process static array (survives across RoadRunner requests).
 * - L2: OPT-IN second tier. {@see useCache()} accepts any PSR-16
 *   CacheInterface — e.g. APCu (azera-cache ApcuCache), Redis, File —
 *   for setups where L1 dies with the request (PHP-FPM). Default is NO
 *   L2: metadata compiles per process, which is always correct.
 *
 *   Invalidating L2 is the USER's responsibility once a backend is
 *   wired: use {@see cacheSalt()} (e.g. a deploy hash, in bootstrap),
 *   a TTL via useCache(), clear() on deploy, or call
 *   Metadata::clear() from an admin route. The VERSION constant
 *   remains the framework-side escape hatch when the compiled shape
 *   changes.
 *
 * Compile-time vs runtime: source/schema/idFields are resolved ONCE here
 * (a declared override is consulted during compilation). Only the runtime
 * connection-role setters (setDefaultReadRole/...) remain per-request
 * dynamic — they sit ABOVE the #[Connection] attribute in precedence.
 */
final class Metadata
{
    /** Bump when compiler output changes shape — invalidates L2 entries. */
    private const VERSION = 'v10';

    /** @var array<class-string, array> */
    private static array $l1 = [];

    /** @var array<class-string, true> classes currently being compiled (re-entrancy guard) */
    private static array $compiling = [];

    /** Opt-in PSR-16 second tier; null = disabled (default). */
    private static ?\Psr\SimpleCache\CacheInterface $backend = null;

    /** TTL (seconds) handed to the backend on writes; null = backend default. */
    private static ?int $ttl = null;

    /** Optional deploy-hash salt — part of the cache key, changes force a recompile. */
    private static ?string $salt = null;

    /** Cache key prefix — also the scope of clear()'s key-index deletion. */
    private const KEY_PREFIX = 'azera_orm_meta';

    /** Index entry listing all class keys written (scope for clear()). */
    private const INDEX_KEY = 'azera_orm_meta_index';
    /**
     * Wire a PSR-16 backend as the L2 metadata cache (e.g. APCu, Redis,
     * File from azera-cache, or any PSR-16 implementation). Pass null to
     * disable L2 again.
     *
     * Invalidating the shared store is then the application's job —
     * typically {@see cacheSalt()} with a deploy hash, or a TTL:
     *
     *     Metadata::useCache(new ApcuCache(), ttl: 86400);
     *     Metadata::cacheSalt(ENV['DEPLOY_HASH']);
     *
     * @param \Psr\SimpleCache\CacheInterface|null $cache backend or null to disable
     * @param int|null $ttl seconds for stored entries (null = backend default)
     */
    public static function useCache(?\Psr\SimpleCache\CacheInterface $cache, ?int $ttl = null): void
    {
        self::$backend = $cache;
        self::$ttl = $ttl;
    }

    /**
     * Set an optional salt mixed into the L2 cache key. Change it (e.g.
     * per deploy: a build hash, git SHA, config version) to force a
     * full recompile of every model — the old keys are simply never
     * requested again (TTL or backend eviction reclaims their space).
     */
    public static function cacheSalt(?string $salt): void
    {
        self::$salt = $salt;
    }

    /**
     * Compile (or fetch from cache) metadata for a class.
     *
     * @param class-string $class
     */
    public static function for(string $class): array
    {
        $class = ltrim($class, '\\');

        if (isset(self::$l1[$class])) {
            return self::$l1[$class];
        }

        $meta = self::fromL2($class) ?? self::compile($class);

        return self::$l1[$class] = $meta;
    }

    /**
     * Forget all cached metadata (tests, deploys, admin routes).
     *
     * L1 and the compiling guard are always reset. With a PSR-16 backend
     * wired, only THIS component's keys are deleted (tracked in a small
     * index entry) — never the whole shared cache segment, which the
     * application may be using for unrelated data.
     */
    public static function clear(): void
    {
        self::$l1 = [];
        self::$compiling = [];

        if (self::$backend === null) {
            return;
        }

        $keys = self::$backend->get(self::INDEX_KEY, []);
        if (\is_array($keys) && $keys !== []) {
            self::$backend->deleteMultiple($keys);
        }
        self::$backend->delete(self::INDEX_KEY);
    }

    /**
     * Forget only the L1 (per-process) tier — any wired L2 stays warm.
     */
    public static function clearL1(): void
    {
        self::$l1 = [];
        self::$compiling = [];
    }

    /**
     * True while the class's metadata is being compiled. Model's
     * metadata-backed accessors check this and fall back to the raw
     * convention, so an override calling parent::source()/idFields()
     * during compilation cannot recurse into compile() again.
     */
    public static function isCompiling(string $class): bool
    {
        return isset(self::$compiling[ltrim($class, '\\')]);
    }

    /* ------------------------------------------------------------- l2 */

    /**
     * PSR-16 keys must be alphanumeric + "._-" and are often length-capped
     * (64 in ArrayCache, various limits in Redis/Memcached adapters), so
     * VERSION, the optional user salt and the class name are hashed into
     * one compact digest — the key is always key-safe and ≤ 50 chars no
     * matter what the salt contains. The payload carries the class and is
     * verified on read — collisions or foreign payloads are treated as a
     * miss.
     */
    private static function cacheKey(string $class): string
    {
        return self::KEY_PREFIX . '_' . md5(
                self::VERSION . "\0" . (self::$salt ?? '') . "\0" . $class
            );
    }

    /** @return array|null null = miss (no backend, fetch failure, wrong class) */
    private static function fromL2(string $class): ?array
    {
        if (self::$backend === null) {
            return null;
        }

        try {
            $meta = self::$backend->get(self::cacheKey($class));
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
            return null; // malformed/unacceptable key → treat as a miss
        }

        return (\is_array($meta) && ($meta['class'] ?? null) === $class) ? $meta : null;
    }

    private static function toL2(string $class, array $meta): void
    {
        if (self::$backend === null) {
            return;
        }

        $key = self::cacheKey($class);

        try {
            self::$backend->set($key, $meta, self::$ttl);
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
            return; // unusable key for this backend → metadata stays L1-only
        }

        // Track written keys so clear() can delete only our entries.
        $keys = self::$backend->get(self::INDEX_KEY, []);
        if (!\is_array($keys)) {
            $keys = [];
        }
        if (!isset($keys[$key])) {
            $keys[$key] = $key;
            try {
                self::$backend->set(self::INDEX_KEY, $keys);
            } catch (\Psr\SimpleCache\InvalidArgumentException) {}
        }
    }

    /* -------------------------------------------------------- compile */

    /**
     * @param class-string $class
     * @return array
     */
    private static function compile(string $class): array
    {
        // Re-entrancy guard: Model's metadata-backed accessors consult
        // isCompiling() and fall back to the raw convention mid-compile, so
        // parent:: calls from overrides cannot recurse. A DIRECT
        // Metadata::for() call from inside a source()/schema()/idFields()
        // override is a user bug — fail loudly instead of looping.
        if (isset(self::$compiling[$class])) {
            throw new \LogicException(
                "Recursive metadata compile for {$class} — an override must not " .
                    'consult Metadata::for() on its own class; call parent:: instead'
            );
        }

        self::$compiling[$class] = true;

        try {
            return self::doCompile($class);
        } finally {
            unset(self::$compiling[$class]);
        }
    }

    private static function doCompile(string $class): array
    {
        $reflect = new ReflectionClass($class);
        $short   = $reflect->getShortName();

        $meta = [
            'class' => $class,
            // Declared source() override or the convention — #[Entity(name)]
            // layers on top ONLY when the method is not overridden
            // (dynamic > static > convention).
            'source' => self::resolveSource($reflect),
            // Same precedence for schema (declared override > #[Entity] > null).
            'schema' => self::resolveSchema($reflect),
            // Generic registry key — 'sql' is the zero-config default; the
            // registered store for this type enriches pkMode below.
            'store'     => 'sql',
            'pkMode'    => null,
            'readRole'  => null,
            'writeRole' => null,
            'columns'   => [],
            'relations' => [],
        ];

        $entityAttrs = $reflect->getAttributes(Entity::class, \ReflectionAttribute::IS_INSTANCEOF);
        $entity      = $entityAttrs === [] ? null : $entityAttrs[0]->newInstance();

        $connAttrs = $reflect->getAttributes(Connection::class, \ReflectionAttribute::IS_INSTANCEOF);
        $conn      = $connAttrs === [] ? null : $connAttrs[0]->newInstance();

        if ($entity !== null) {
            // The store key is opaque routing config — the registry lookup
            // (EM fallback / registered store) gives it meaning.
            if ($entity->store !== null) {
                $meta['store'] = $entity->store;
            }
            // The attribute fills only what the model does not already
            // declare: a source()/schema() override wins (dynamic >
            // static); the attribute beats the convention.
            if ($entity->name !== null && !self::overridesMethod($reflect, 'source', \Azera\Orm\Model::class)) {
                $meta['source'] = $entity->name;
            }
            if ($entity->schema !== null && !self::overridesMethod($reflect, 'schema', \Azera\Orm\Model::class)) {
                $meta['schema'] = $entity->schema;
            }
        }
        if ($conn !== null) {
            // `role` sets both directions; explicit read/write win per side.
            // Store-agnostic in core — each store validates during
            // enrichment whether it can honor per-class connection roles.
            $meta['readRole']  = $conn->read ?? $conn->role;
            $meta['writeRole'] = $conn->write ?? $conn->role;
        }

        // Store-driven enrichment BEFORE PK resolution: the store's pkMode
        // decides which PK chain runs. Unregistered store type = no
        // enrichment (the documented ordering rule: register stores before
        // first metadata use of the classes they serve).
        $meta = self::enrichFromStore($meta, $reflect);

        // Explicit #[Column(pk)] marks (true OR false) — kept separately
        // from the resolved flag, which also carries the id/*_id name
        // convention guess until PK resolution below.
        $explicitPk = [];

        foreach ($reflect->getProperties() as $prop) {
            // Non-instance, write-once, or non-public properties are never persisted.
            if ($prop->isStatic() || $prop->isReadOnly() || !$prop->isPublic()) {
                continue;
            }

            $column = self::columnAttribute($prop);
            if ($column !== null && !$column->persist) {
                continue;
            }

            $relation = self::relationAttribute($prop);
            if ($relation !== null) {
                $meta['relations'][$prop->name] = self::compileRelation($prop->name, $relation, $short);
                continue;
            }

            // Effective type: explicit #[Column(type:)] or the PHP-type
            // inference — needed by BOTH the metadata entry and the cast
            // policy resolution below.
            $type = $column?->type ?? self::inferType($prop);

            // A PURE (non-backed) enum has no scalar representation, so it
            // cannot round-trip through any store: encoding it would ship
            // the case object to the driver and decoding would have to
            // invent a value. Reject it here, where the property is still
            // identifiable, instead of failing later at the bind site.
            if (\enum_exists($type) && !\is_subclass_of($type, \BackedEnum::class)) {
                throw new \LogicException(
                    "Cannot persist {$class}::\${$prop->name}: {$type} is a pure enum with no scalar representation. "
                        . 'Give it a backing type (enum ' . $type . ': string), '
                        . 'or declare #[Column(type: ...)] with an explicit scalar column type.'
                );
            }

            // Resolved cast policy (explicit #[Column(cast:)] vs the
            // store's castExclusions) — computed here because the guard
            // below needs it and the metadata entry records it.
            $castPolicy = $column?->cast ?? !\in_array($type, $meta['castExclusions'] ?? [], true);

            // An ENUM-typed property REQUIRES an active cast: the cast is
            // the only thing that turns the store's scalar into a case.
            // Without it hydration assigns the raw scalar onto the
            // property (TypeError) and the write path binds the case
            // object. Same refusal shape as resolveNullable()'s: the
            // combination is unrepresentable, so it fails at compile time
            // with the property named, rather than deep inside hydration.
            if ($castPolicy === false) {
                $propType = $prop->getType();
                if (
                    $propType instanceof ReflectionNamedType
                        && !$propType->isBuiltin()
                        && \enum_exists($propType->getName())
                ) {
                    throw new \LogicException(
                        "Cannot persist {$class}::\${$prop->name}: the property is enum-typed ({$propType->getName()}) "
                            . 'but the column\'s cast is SUPPRESSED, and the cast is what converts between the enum case '
                            . 'and the stored scalar. Drop #[Column(cast: false)], or declare the property untyped/scalar '
                            . 'if you really want the raw representation.'
                    );
                }
            }

            $nullable = self::resolveNullable($class, $prop, $column);

            $meta['columns'][$prop->name] = [
                'name' => $column?->name ?? $prop->name,
                'type' => $type,
                // The COMPLETE null policy for this column — it is the DDL
                // answer AND the hydration answer (see resolveNullable()'s
                // "nullable ⇒ the property accepts null" invariant), so
                // hydration reads this flag alone.
                'nullable' => $nullable,
                // Whether the property carries a PHP type declaration.
                // A typed property is assigned in WEAK mode: PHP coerces
                // a driver string ('5') to the declared type on write, so
                // hydration MUST read the value back OFF the property for
                // the heap snapshot (weak-mode coercion is the phantom
                // UPDATE's root). An UNTYPED property cannot coerce — the
                // assigned value IS the stored value — so hydration can
                // skip the read-back and keep the raw cell in the
                // snapshot. This flag is what arms that fast path.
                'typed' => $prop->getType() !== null,
                // Baseline: convention guess (id / *_id) for unnamed
                // columns; renamed columns are no longer convention-matched.
                // Finalized per store/model kind below.
                'pk' => $column?->name === null
                    ? ($prop->name === 'id' || str_ends_with($prop->name, '_id'))
                    : false,
                // Cast policy, resolved above (compile time) against the
                // store-contributed castExclusions: true/false = explicit
                // #[Column(cast:)] override; the fallback = AUTO (cast
                // unless the store excluded this type — its native wire
                // format).
                'cast' => $castPolicy,
            ];

            if ($column?->pk !== null) {
                $explicitPk[$prop->name] = $column->pk;
            }
        }

        $isModel = $reflect->isSubclassOf(\Azera\Orm\Model::class);

        if (($meta['pkMode'] ?? 'model-chain') === 'model-chain' && $isModel) {
            // 1) A DECLARED idFields() override is the PK authority: the
            //    name convention alone misses custom keys ('uid') and would
            //    wrongly mark FK-like columns (*_id that are not part of
            //    the declared key) as PKs. Mid-compile recursion (an
            //    override calling parent::idFields()) is handled by the
            //    isCompiling guard → ['id'] fallback.
            if (self::overridesMethod($reflect, 'idFields', \Azera\Orm\Model::class)) {
                try {
                    $idFields = $reflect->newInstanceWithoutConstructor()->idFields();
                    foreach ($meta['columns'] as $field => $col) {
                        $meta['columns'][$field]['pk'] = \in_array($field, $idFields, true);
                    }
                } catch (\Throwable) {}
            } elseif ($explicitPk !== []) {
                // 2) Explicit #[Column(pk)] marks DEFINE the key for SQL
                //    models without an override — one explicit mark
                //    switches the whole class off the id/*_id convention,
                //    so a *_id FK column never leaks into the PK.
                foreach ($meta['columns'] as $field => $col) {
                    $meta['columns'][$field]['pk'] = $explicitPk[$field] ?? false;
                }
            } else {
                // 3) Residual default = ['id'] (the base idFields()): the
                //    *_id convention guess is NOT a PK for SQL Models.
                foreach ($meta['columns'] as $field => $col) {
                    $meta['columns'][$field]['pk'] = $field === 'id';
                }
            }
        } elseif ($explicitPk !== []) {
            // Plain classes and mongo documents: explicit marks layer on
            // top of the name convention (mixed marks + convention are
            // combined — documents commonly mark nothing and rely on _id).
            foreach ($explicitPk as $field => $marked) {
                if (isset($meta['columns'][$field])) {
                    $meta['columns'][$field]['pk'] = $marked;
                }
            }
        }

        // Resolved PK list in declaration order — the single source of
        // truth consumers read instead of re-scanning columns[].pk.
        // Mirrors idFields() exactly: marks > declared override > ['id'].
        $meta['pkFields'] = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk']) {
                $meta['pkFields'][] = $field;
            }
        }
        if ($meta['pkFields'] === []) {
            $meta['pkFields'] = ['id'];
        }

        self::toL2($class, $meta);

        return $meta;
    }

    /**
     * Store-driven enrichment at compile time: resolve the registered store
     * for the class's `store` type via the context holder and let it
     * contribute backend-specific metadata ({@see MetadataContributor}) —
     * before PK resolution, because the store's pkMode decides which PK
     * chain runs.
     *
     * Unregistered type = no enrichment (plain skip, no error): the
     * documented ordering rule is that stores are registered before the
     * first metadata use of the classes they serve. This is also what keeps
     * metadata compilation store-optional (zero-config SQL path).
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function enrichFromStore(array $meta, ReflectionClass $reflect): array
    {
        $ctx = \Azera\AppContext::instance();

        $store = $ctx->get(Stores::class)->tryGet($meta['store']);
        if ($store === null) {
            return $meta;
        }
        return $store->enrichMetadata($meta, $reflect);
    }

    /**
     * Resolve the effective source (table) name for a class — WITHOUT
     * attribute application (doCompile layers #[Entity(name)] on top).
     *
     * A DECLARED source() override (method declared closer than Model)
     * is consulted at compile time — the Store seam must target the same
     * table the Query builder uses. Plain classes (ORM test fixtures)
     * use the convention.
     */
    private static function resolveSource(\ReflectionClass $reflect): string
    {
        $convention = ModelMapping::convertModelToSource($reflect->getShortName());

        if (!$reflect->isSubclassOf(\Azera\Orm\Model::class)) {
            return $convention;
        }

        if (!self::overridesMethod($reflect, 'source', \Azera\Orm\Model::class)) {
            return $convention;
        }

        try {
            $override = $reflect->newInstanceWithoutConstructor()->source();
        } catch (\Throwable) {
            return $convention;
        }

        return $override !== '' ? $override : $convention;
    }

    /**
     * Resolve the declared schema() override — WITHOUT attribute
     * application (doCompile layers #[Entity(schema)] on top). Null for
     * plain classes and non-overriding models.
     */
    private static function resolveSchema(\ReflectionClass $reflect): ?string
    {
        if (!$reflect->isSubclassOf(\Azera\Orm\Model::class)) {
            return null;
        }

        if (!self::overridesMethod($reflect, 'schema', \Azera\Orm\Model::class)) {
            return null;
        }

        try {
            return $reflect->newInstanceWithoutConstructor()->schema();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when $reflect declares $method itself (or in a base closer
     * than $baseClass) — i.e. the inherited base implementation is
     * overridden and carries user intent rather than the default.
     */
    private static function overridesMethod(\ReflectionClass $reflect, string $method, string $baseClass): bool
    {
        if (!$reflect->hasMethod($method)) {
            return false;
        }

        return $reflect->getMethod($method)->getDeclaringClass()->getName() !== $baseClass;
    }

    private static function relationAttribute(ReflectionProperty $prop): ?object
    {
        foreach ([BelongsTo::class, HasOne::class, HasMany::class] as $attr) {
            $found = $prop->getAttributes($attr, \ReflectionAttribute::IS_INSTANCEOF);
            if ($found !== []) {
                return $found[0]->newInstance();
            }
        }

        return null;
    }

    private static function columnAttribute(ReflectionProperty $prop): ?Column
    {
        $found = $prop->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF);

        return $found === [] ? null : $found[0]->newInstance();
    }

    /**
     * @return array<string, mixed>
     */
    private static function compileRelation(string $name, object $relation, string $short): array
    {
        if ($relation instanceof BelongsTo) {
            return [
                'type'       => 'belongsTo',
                'target'     => $relation->target,
                'foreignKey' => $relation->foreignKey ?? ($name . '_id'),
                'ownerKey'   => $relation->ownerKey ?? 'id',
                'strategy'   => 'join',
            ];
        }

        // HasOne / HasMany: the foreign key lives on the TARGET table and
        // references THIS model.
        return [
            'type'       => $relation instanceof HasOne ? 'hasOne' : 'hasMany',
            'target'     => $relation->target,
            'foreignKey' => $relation->foreignKey
                ?? ModelMapping::convertModelToSource($short) . '_id',
            'ownerKey' => $relation->ownerKey ?? 'id',
            'strategy' => $relation instanceof HasOne ? 'join' : 'second_query',
        ];
    }

    private static function inferType(ReflectionProperty $prop): string
    {
        $type = $prop->getType();

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            // A backed enum IS its own column type: the class-string is the
            // cast registry key ({@see \Azera\Orm\Casting\Casts}), which
            // resolves to an EnumCast for that enum — so an enum-typed
            // property round-trips with zero configuration. Pure enums are
            // rejected by the caller (they reach the 'string' fallback).
            if (!$type->isBuiltin() && \enum_exists($name)) {
                return $name;
            }

            return match ($name) {
                'int'                                                => 'int',
                'float'                                              => 'float',
                'bool'                                               => 'bool',
                'DateTimeInterface', 'DateTime', 'DateTimeImmutable' => 'datetime',
                'array'                                              => 'json',
                default                                              => 'string'
            };
        }

        return 'string';
    }

    /**
     * Resolve the column's nullability from its TWO possible declarations
     * — the PHP type (`?int`) and `#[Column(nullable:)]`.
     *
     * The attribute is TRI-STATE and the PHP type supplies the default:
     *
     * - null (default): the PHP type decides — `?T` or untyped → nullable,
     *   `T` → NOT nullable. The zero-config path.
     * - false (explicit downgrade): NOT nullable, even on a `?T` or
     *   untyped property. That is a REQUEST, not a contradiction: the
     *   column is NOT NULL in the DDL while the property could hold a
     *   null it will never receive (a store NULL is rejected on
     *   hydration). Honored.
     * - true (explicit upgrade): nullable — legal only where the property
     *   ALREADY accepts null (`?T`, untyped), where it is a redundant
     *   confirmation of the resolved default.
     *
     * WHY `T` + `nullable: true` is rejected: the column would allow NULL
     * while the property cannot represent it, and there is no third
     * option left to honor it — hydration may only assign null or throw,
     * and "leave the property uninitialized" is indistinguishable from
     * "never loaded". Failing at compile time is the honest answer; the
     * fix is to make the property nullable (`?T`) or drop the attribute.
     *
     * WHY `?T` + `nullable: false` is NOT rejected: nothing is
     * unrepresentable. The column is simply NOT NULL and a store NULL is
     * rejected on hydration — the same treatment an untyped property gets.
     *
     * INVARIANT upheld for the rest of the ORM:
     *     nullable === true  ⇒  the property accepts null
     * Both `true` paths require it — the explicit upgrade is rejected
     * otherwise, and the type-derived default only yields true when the
     * property allows null. Hydration therefore reads `nullable` ALONE:
     * a null is assignable exactly when the column is nullable, and
     * otherwise it is rejected. That is why no second gate flag exists.
     *
     * @param class-string $class the owning class, for the error message
     */
    private static function resolveNullable(string $class, ReflectionProperty $prop, ?Column $column): bool
    {
        $type = $prop->getType();

        $phpNullable = $type === null || $type->allowsNull();
        $colNullable = $column?->nullable;

        if (!$phpNullable && $colNullable) {
            throw new \LogicException(
                "Contradictory nullability on {$class}::\${$prop->name}: the PHP type does not allow NULL, but #[Column(nullable: true)] declares the column NULLABLE."
            );
        }

        return $colNullable ?? $phpNullable;
    }
}