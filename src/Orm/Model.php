<?php

namespace Azera\Orm;

use Azera\AppContext;
use Azera\Db\Database;
use Azera\Db\ModelMapping;
use Azera\Db\Query;
use Azera\Db\Resolver\ModelResolver;

/**
 * Active-Record model over the {@see EntityManager}.
 *
 * ONE pipeline for everything: reads go through the EM's identity map
 * ({@see find()} / {@see findOne()} / {@see findAll()} — same PK read
 * twice in a request yields the same instance), writes delegate to
 * persist + flush (diff -> order -> transaction -> ID backfill). The
 * query builder remains available for advanced reads via {@see query()}
 * / {@see with()} — its entities()/firstEntity() terminals hydrate onto
 * the SAME heap, so builder reads and facade reads share identity.
 */
#[\AllowDynamicProperties]
abstract class Model
{
    use Concerns\EntityFacade;

    /* -------------------------------------------------------------
     *  MODEL CONFIG
     * ------------------------------------------------------------- */

    /**
     * Return the table or view name for this model.
     *
     * Metadata-backed: #[Entity(name: ...)] > a declared source()
     * override > the naming convention (short class name, snake_case,
     * optional pluralization — e.g. User → users, AdminUser →
     * admin_users, Person → people). Overriding the method still wins
     * (dynamic > static); such an override may call parent::source()
     * for the convention value — the compile-time re-entrancy guard
     * keeps that recursion-free.
     */
    public function source(): string
    {
        if (Metadata::isCompiling(static::class)) {
            $pos   = strrpos(static::class, '\\');
            $class = $pos !== false
                ? substr(static::class, $pos + 1)
                : static::class;
            return ModelMapping::convertModelToSource($class);
        }

        return Metadata::for(static::class)['source'];
    }

    /**
     * Return the database schema for this model, if applicable
     * (e.g. PostgreSQL). Metadata-backed: #[Entity(schema: ...)] > a
     * declared schema() override > null.
     */
    public function schema(): ?string
    {
        if (Metadata::isCompiling(static::class)) {
            return null;
        }

        return Metadata::for(static::class)['schema'] ?? null;
    }

    /**
     * Return the primary key field(s) for this model.
     *
     * Metadata-backed ('pkFields' — the resolved PK list): fields marked
     * #[Column(pk: true)] — multiple marks compose a composite key —
     * falling back to ['id']. A declared idFields() override is the
     * authority (dynamic > static); parent::idFields() from such an
     * override resolves through the compile-time re-entrancy guard.
     */
    public function idFields(): array
    {
        if (Metadata::isCompiling(static::class)) {
            return ['id'];
        }

        return Metadata::for(static::class)['pkFields'];
    }

    /* -------------------------------------------------------------
     *  STATIC QUERY BUILDER
     * ------------------------------------------------------------- */

    /**
     * Start a new query builder for this model. By default, it creates a Query with the model's source as the table.
     * Its entities()/firstEntity() terminals hydrate heap-tracked entities on the request-scoped identity map.
     * @param string|null $alias Optional alias for the model in the query
     * @return Query<static>
     */
    public static function query(?string $alias = null): Query
    {
        return (new Query)
            ->using(AppContext::instance()->get(ModelResolver::class))
            ->table(static::class, $alias);
    }

    /**
     * Start a query with eager-loaded relations.
     * BelongsTo/HasOne become LEFT JOINs (one SQL, alias-separated rows);
     * HasMany stays a second query by parent IDs. Relation names must be
     * declared via Orm attributes on the model.
     *
     * @param string ...$relations Relation names from Orm metadata
     * @return Query<static>
     */
    public static function with(string ...$relations): Query
    {
        $query = static::query();

        foreach ($relations as $name) {
            $query->with($name);
        }

        return $query;
    }

    /**
     * Start a fresh-read query: identity-map hits come back refreshed in
     * place (same instance, current values) instead of stale.
     *
     * @return Query<static>
     */
    public static function fresh(): Query
    {
        return static::query()->fresh();
    }

    /* -------------------------------------------------------------
     *  CREATE
     *
     * create() / upsert() / firstOrCreate() / updateOrCreate() come from
     * the EntityFacade trait: they go through the EntityManager's Store
     * seam, so they work on every backend (SQL and documents alike).
     * ------------------------------------------------------------- */

    /* -------------------------------------------------------------
     *  READS — all through the EntityManager identity map
     * ------------------------------------------------------------- */

    /*
     * find() / findOrFail() / findOne() / findAll() come from the
     * EntityFacade trait: they go through the EntityManager's Store seam,
     * so they work on every backend (SQL and documents alike).
     *
     * exists() / count() below are builder-based and therefore SQL-only —
     * `Query` refuses a non-SQL store rather than querying the wrong
     * database.
     */

    /**
     * Check whether any record matches the given conditions.
     *
     * Builder-backed and therefore **SQL-only**: on a class routed to
     * another store (e.g. `#[Entity(store: 'mongo')]`) this throws a
     * `LogicException` rather than querying the wrong database. Use
     * `findOne($conditions) !== null` or `$em->findBy(...)` for a
     * backend-agnostic existence check.
     *
     * @param array $conditions Associative array of field conditions to check for existence
     * @return bool True if a matching model exists, false otherwise
     */
    public static function exists(array $conditions): bool
    {
        $query = static::query();
        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }
        return $query->exists();
    }

    /**
     * Count records matching the given conditions.
     *
     * Builder-backed and therefore **SQL-only** — see {@see exists()} for
     * the backend-agnostic alternative.
     *
     * @param array $conditions Associative array of field conditions to count
     * @return int The count of matching models
     */
    public static function count(array $conditions = []): int
    {
        $query = static::query();
        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }
        return (int) $query->count();
    }

    /* -------------------------------------------------------------
     *  SAVE / DELETE — facade over the EM write pipeline
     * ------------------------------------------------------------- */

    /**
     * Save the model through the {@see EntityManager} — the ONE
     * write pipeline for facade and EM-direct use.
     *
     * Untracked entity: adopt() first. With all ID fields set the baseline
     * is empty → the flush writes every set column (legacy blind-UPDATE
     * parity for manually built models); without IDs it schedules INSERT
     * and the EM backfills auto-generated PKs.
     *
     * Returns true when a write happened (a no-op flush — nothing scheduled
     * — means the model was already in sync).
     */
    public function save(): bool
    {
        $em = AppContext::instance()->entityManager();

        // Adopt only entities with a full identity: without all ID fields
        // set there is nothing to adopt — the EM must schedule an INSERT
        // (adopting a PK-less model as MANAGED would diff-dirty it into an
        // UPDATE with WHERE 1=0).
        $hasAllIds = true;
        foreach ($this->idFields() as $field) {
            if (!isset($this->$field)) {
                $hasAllIds = false;
                break;
            }
        }

        if (!$em->contains($this) && $hasAllIds) {
            $em->adopt($this);
        }

        $em->persist($this);

        if (!$em->isScheduled($this)) {
            return false; // clean: nothing to write
        }

        $em->flush();

        return true;
    }

    /**
     * Delete the model through the {@see EntityManager} (remove + flush —
     * one DELETE by PK identity). Requires that all ID fields are set;
     * throws otherwise.
     *
     * The guard below is kept for its facade-specific message (documented in
     * `examples/SaveCreateUpdateExample.php`); `{@see EntityManager::remove()}`
     * enforces the same invariant and adopts untracked instances, so nothing
     * else is needed here.
     *
     * @return bool True if the delete was successful
     */
    public function delete(): bool
    {
        foreach ($this->idFields() as $field) {
            if (!isset($this->$field)) {
                throw new \RuntimeException("ID field '$field' not set");
            }
        }

        AppContext::instance()->entityManager()->remove($this)->flush();

        return true;
    }

    /**
     * Re-read this model's row from storage and refresh the instance IN
     * PLACE: current values onto $this, heap snapshot synced as the new
     * diff baseline. Returns $this, or NULL when the row is gone in
     * storage (detached from the identity map). Throws for untracked
     * models and models with scheduled unflushed writes.
     */
    public function refresh(): ?static
    {
        $found = AppContext::instance()->entityManager()->refresh($this);

        return $found instanceof static ? $found : null;
    }

    /* -------------------------------------------------------------
     *  DIRTY STATE
     * ------------------------------------------------------------- */

    /**
     * Whether any field differs from the heap baseline (untracked entity:
     * true when any metadata column has a set value).
     */
    public function hasChanged(): bool
    {
        return AppContext::instance()->entityManager()->isDirty($this);
    }

    /**
     * Field-name-keyed map of values that differ from the heap baseline
     * (untracked entity: all set values).
     *
     * @return array<string, mixed>
     */
    public function changedData(): array
    {
        return AppContext::instance()->entityManager()->dirtyData($this);
    }

    /**
     * Revert all properties to the values recorded in the heap node
     * snapshot (the loadState() replacement). No-op for untracked entities.
     */
    public function loadState(): static
    {
        AppContext::instance()->entityManager()->revert($this);
        return $this;
    }

    /* -------------------------------------------------------------
     *  CONNECTIONS
     * ------------------------------------------------------------- */

    /**
     * Per-class read/write role overrides.
     *
     * @var array<string, string>
     */
    protected static array $__defaultReadRoles = [];

    /** @var array<string, string> */
    protected static array $__defaultWriteRoles = [];

    /**
     * Set both the read and write database role for this model class.
     *
     * @param string $role Named role registered with {@see \Azera\Db\DatabaseManager}.
     */
    public static function setDefaultRole(string $role): void
    {
        self::$__defaultReadRoles[static::class] = $role;
        self::$__defaultWriteRoles[static::class] = $role;
    }

    /**
     * Set the database role used for SELECT queries on this model class.
     *
     * @param string $role Named read role registered with {@see \Azera\Db\DatabaseManager}.
     */
    public static function setDefaultReadRole(string $role): void
    {
        self::$__defaultReadRoles[static::class] = $role;
    }

    /**
     * Set the database role used for INSERT/UPDATE/DELETE queries on this model class.
     *
     * @param string $role Named write role registered with {@see \Azera\Db\DatabaseManager}.
     */
    public static function setDefaultWriteRole(string $role): void
    {
        self::$__defaultWriteRoles[static::class] = $role;
    }

    protected function __connectionRole(string $type): string
    {
        $map = $type === 'read'
            ? static::$__defaultReadRoles
            : static::$__defaultWriteRoles;

        // 1. Concrete-model runtime override — per-request tenancy and
        //    other dynamic routing decided in code.
        if (isset($map[static::class])) {
            return $map[static::class];
        }

        // 2. Declarative #[Connection] attribute (compiled metadata).
        $metaRole = Metadata::for(static::class)[$type === 'read' ? 'readRole' : 'writeRole'] ?? null;
        if ($metaRole !== null) {
            return $metaRole;
        }

        // 3. Base-model global runtime override.
        if (isset($map[self::class])) {
            return $map[self::class];
        }

        // 4. Fallback to the role name itself.
        return $type;
    }

    /**
     * Return the database connection role used for read (SELECT) queries.
     *
     * @return string The role name (e.g. 'read', 'replica').
     */
    public function readRole(): string
    {
        return $this->__connectionRole('read');
    }

    /**
     * Return the database connection role used for write (INSERT/UPDATE/DELETE) queries.
     *
     * @return string The role name (e.g. 'write', 'primary').
     */
    public function writeRole(): string
    {
        return $this->__connectionRole('write');
    }

    /**
     * Return the database connection used for read (SELECT) queries.
     *
     * Resolves the configured read role via {@see \Azera\Db\DatabaseManager::getOrDefault()}.
     *
     * @return \Azera\Db\Database
     */
    public function readConnection(): Database
    {
        $role = $this->__connectionRole('read');
        return AppContext::instance()->dbManager()->getOrDefault($role);
    }

    /**
     * Return the database connection used for write (INSERT/UPDATE/DELETE) queries.
     *
     * Resolves the configured write role via {@see \Azera\Db\DatabaseManager::getOrDefault()}.
     *
     * @return \Azera\Db\Database
     */
    public function writeConnection(): Database
    {
        $role = $this->__connectionRole('write');
        return AppContext::instance()->dbManager()->getOrDefault($role);
    }
}