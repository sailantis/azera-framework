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
     * ------------------------------------------------------------- */

    /**
     * Create a new model instance with the given values and save it to the database. Returns the created instance.
     * @param array $values Associative array of field values to set on the new model
     * @return static The created model instance
     */
    public static function create(array $values): static
    {
        $instance = new static();

        foreach ($values as $key => $value) {
            $instance->$key = $value;
        }

        $instance->save();
        return $instance;
    }

    /**
     * Create or update a model using database-level UPSERT semantics
     * (INSERT ... ON CONFLICT DO UPDATE). A single atomic statement with
     * no prior SELECT — the database handles the conflict resolution.
     *
     * Routed through the {@see EntityManager}: the entity lands in the
     * identity map (MANAGED after flush) and the statement joins any open
     * flush transaction. All ID fields must be present in $values so the
     * conflict target is well-defined; on conflict, all non-ID fields
     * from $values are updated.
     *
     * @param array $values Associative array of field values (must include all ID fields)
     * @return static The model instance with the given values
     */
    public static function upsert(array $values): static
    {
        $instance = new static();

        foreach ($values as $key => $value) {
            $instance->$key = $value;
        }

        $em = AppContext::instance()->entityManager();
        $em->upsert($instance);
        $em->flush();

        return $instance;
    }

    /**
     * Find the first model matching the given conditions or create a new one with the combined conditions and values if none found. This is useful for ensuring a record exists without creating duplicates. Returns the found or created instance.
     * @param array $conditions Associative array of field conditions to find the model
     * @param array $values Additional values to set on the model if it needs to be created (merged with conditions)
     * @return static The found or created model instance
     */
    public static function firstOrCreate(array $conditions, array $values = []): static
    {
        $model = static::findOne($conditions);

        if ($model) {
            return $model;
        }

        return static::create(array_merge($conditions, $values));
    }

    /**
     * Find the first model matching the given conditions or update it with the provided values if found, otherwise create a new one with the combined conditions and values. This is useful for ensuring a record exists and is up to date without creating duplicates. Returns the found, updated, or created instance.
     * @param array $conditions Associative array of field conditions to find the model
     * @param array $values Values to set on the model if found (updated) or merged with conditions if created
     * @return static The found, updated, or created model instance
     */
    public static function updateOrCreate(array $conditions, array $values = []): static
    {
        $model = static::findOne($conditions);

        if ($model) {
            foreach ($values as $key => $value) {
                $model->$key = $value;
            }
            $model->save();
            return $model;
        }

        return static::create(array_merge($conditions, $values));
    }

    /* -------------------------------------------------------------
     *  READS — all through the EntityManager identity map
     * ------------------------------------------------------------- */

    /**
     * Find a model by its ID(s) through the EntityManager: heap probe
     * first, one Store read on miss, hydration onto the shared heap. The
     * returned instance is identity-mapped — the same row read twice in
     * one request yields the same object.
     *
     * $fresh=true re-reads the row and refreshes the tracked instance IN
     * PLACE (same object, current values) — the polling-safe variant.
     * Throws when the entity carries scheduled unflushed writes.
     *
     * @param mixed $id Single ID value, or array of ID values (numeric list
     *                  matching idFields order, or field => value map for
     *                  composite keys)
     */
    public static function find(mixed $id, bool $fresh = false): ?static
    {
        $idFields = (new static())->idFields();
        $idMap    = self::mapId($id, $idFields);

        $found = AppContext::instance()->entityManager()->find(static::class, $idMap, $fresh);

        return $found instanceof static ? $found : null;
    }

    /**
     * Finds a model by its ID(s) or throws an exception if not found
     * @param mixed $id Single ID value or array of ID values (for composite keys)
     * @param bool $fresh Re-read the row and refresh the tracked instance in place
     * @throws \RuntimeException if the model is not found
     */
    public static function findOrFail(mixed $id, bool $fresh = false): static
    {
        $model = static::find($id, $fresh);
        if (!$model) {
            throw new \RuntimeException(static::class . " not found");
        }
        return $model;
    }

    /**
     * Find the first model matching the given conditions via the
     * EntityManager (bound parameters, metadata-mapped columns,
     * heap-tracked result), or null when nothing matches.
     *
     * $fresh=true refreshes already-tracked hits in place (same instance,
     * current values).
     *
     * @param array $conditions Associative array of field conditions to find the model
     */
    public static function findOne(array $conditions, bool $fresh = false): ?static
    {
        $rows = AppContext::instance()->entityManager()->findBy(static::class, $conditions, $fresh);

        $first = $rows[0] ?? null;
        return $first instanceof static ? $first : null;
    }

    /**
     * Find all models matching the given conditions as heap-tracked
     * entities (identity-mapped, ordered by row order). If no conditions
     * are provided, it returns all models.
     *
     * @param array $conditions Associative array of field conditions to find the models
     * @return list<static> The found model instances
     */
    public static function findAll(array $conditions = [], bool $fresh = false): array
    {
        $query = static::query();
        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }
        return $query->fresh($fresh)->entities();
    }

    /**
     * Check if any model exists matching the given conditions. Returns true if at least one record matches, false otherwise.
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
     * Count the number of models matching the given conditions. Returns the count as an integer.
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

    /**
     * Normalize the many accepted ID shapes into the EM's
     * field => value map. Scalar IDs require a single-PK model.
     *
     * @param mixed $id
     * @param list<string> $idFields
     * @return array<string, mixed>
     */
    private static function mapId(mixed $id, array $idFields): array
    {
        if (!\is_array($id)) {
            if (\count($idFields) !== 1) {
                throw new \RuntimeException(
                    'Composite key models require an array of ID values (' . implode(', ', $idFields) . ')'
                );
            }
            return [$idFields[0] => $id];
        }

        if (\count($id) !== \count($idFields)) {
            throw new \RuntimeException("ID array count mismatch");
        }

        // Numeric list: values are ordered like idFields.
        if (array_is_list($id)) {
            $map = [];
            foreach ($idFields as $i => $field) {
                if (!isset($id[$i])) {
                    throw new \RuntimeException("Missing ID value for field '$field'");
                }
                $map[$field] = $id[$i];
            }
            return $map;
        }

        // Associative: keys must match idFields.
        $map = [];
        foreach ($idFields as $field) {
            if (!isset($id[$field])) {
                throw new \RuntimeException("Missing ID value for field '$field'");
            }
            $map[$field] = $id[$field];
        }
        return $map;
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
     * Delete the model through the {@see EntityManager}
     * (adopt + remove + flush — one DELETE by PK identity). Requires that
     * all ID fields are set; throws otherwise.
     * @return bool True if the delete was successful
     */
    public function delete(): bool
    {
        foreach ($this->idFields() as $field) {
            if (!isset($this->$field)) {
                throw new \RuntimeException("ID field '$field' not set");
            }
        }

        $em = AppContext::instance()->entityManager();
        if (!$em->contains($this)) {
            $em->adopt($this);
        }

        $em->remove($this)->flush();

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