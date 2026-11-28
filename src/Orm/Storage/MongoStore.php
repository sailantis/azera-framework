<?php

declare(strict_types=1);

namespace Azera\Orm\Storage;

use Azera\Db\ModelMapping;
use Azera\Orm\ConflictTarget;
use Azera\Orm\Metadata;
use MongoDB\Client;
use MongoDB\Collection as MongoCollection;

/**
 * MongoDB backend of the {@see Store} seam over the mongodb/mongodb library.
 *
 * The stack is two layers, NOT two alternatives (unlike redis's phpredis vs
 * predis): ext-mongodb (PECL) is THE driver — MongoDB\Driver\Manager, BSON
 * encoding, wire protocol — and mongodb/mongodb (composer) is the pure-PHP
 * convenience API on top of it ({@see Client}, {@see MongoCollection}). The
 * library cannot run without the extension; using this store = using both.
 *
 * OWNS its connection (the inverse of PdoStore's borrow model): mongo has no
 * role-based read/write split in the DatabaseManager, so the store wraps one
 * {@see Client} and resolves the per-class collection from metadata
 * (`collection` ?? snake/plural convention). A class belongs to exactly one
 * collection, declared by #[Entity(name)].
 *
 * Constructor accepts EITHER a Client (production) OR a collection resolver
 * `fn(string $name): MongoCollection` — the test seam: an in-memory fake
 * collection keeps the suite hermetic (no live server, no flaky CI).
 *
 * Rows are plain assoc arrays keyed by the metadata COLUMN names — identical
 * shape contract to PdoStore — so EntityManager's write pipeline, heap diff,
 * and FastHydrator work unchanged.
 *
 * Identity: mongo's `_id` is THE PK — single, always present (driver-generated
 * ObjectId on insert when omitted). The metadata pk convention (*_id marks)
 * already resolves `$_id` for documents, and insert backfill maps the
 * inserted id onto it.
 *
 * Transactions: no-ops. Multi-document ACID needs replica-set sessions —
 * deliberately deferred (documented); the Store seam's begin/commit/rollback
 * is satisfied structurally so the EM pipeline works against single-server
 * deployments.
 */
final class MongoStore implements Store
{
    /** @var array<string, MongoCollection> class => resolved collection */
    private array $collections = [];

    /** @var ?Client wrapped client (null when a resolver was injected) */
    private ?Client $client;

    /** @var ?callable(string): MongoCollection collection resolver (null when a client was injected) */
    private $resolver;

    /**
     * @param Client|callable(string): MongoCollection $clientOrResolver
     *
     * Optional-dependency boundary: mongodb/mongodb is a `suggest` (not
     * `require`). The `use MongoDB\...` imports here are lazy aliases —
     * loading this class never fatals — and the resolver seam (test
     * fakes) needs no package at all. The failure shape that can actually
     * occur without the package: a real Client instance CANNOT be passed
     * (its class doesn't exist, so it can't be constructed anywhere), so
     * a non-callable argument can only be a mistake — most likely a DSN
     * string in the Client-ctor shape. Convert the cryptic union
     * TypeError into the actionable install hint.
     */
    public function __construct(
        Client|callable $clientOrResolver,
        private string $database = 'test',
    ) {
        if (!$clientOrResolver instanceof Client && !\is_callable($clientOrResolver)) {
            $hint = \class_exists(\MongoDB\Client::class)
                ? 'Pass a MongoDB\Client instance or a collection resolver fn(string $name): MongoCollection.'
                : 'Pass a collection resolver fn(string $name): MongoCollection, or install the'
                    . ' mongodb/mongodb composer package + ext-mongodb PHP extension to use a real'
                    . ' MongoDB\Client: composer require mongodb/mongodb.';
            throw new \RuntimeException(
                'MongoStore expects MongoDB\Client or a collection resolver, '
                    . \get_debug_type($clientOrResolver) . ' given. ' . $hint
            );
        }

        if ($clientOrResolver instanceof Client) {
            $this->client   = $clientOrResolver;
            $this->resolver = null;
        } else {
            $this->client   = null;
            $this->resolver = $clientOrResolver;
        }
    }

    public function insertOne(string $class, array $data): array
    {
        $meta = Metadata::for($class);
        $pk   = self::pkName($meta);

        $doc = $data;
        // Unset _id (null) = let the driver generate the ObjectId; anything
        // set passes through as-is (ObjectId or string, driver maps it).
        if ($pk === '_id' && ($doc[$pk] ?? null) === null) {
            unset($doc[$pk]);
        }

        $result = $this->collection($meta)->insertOne($doc);
        $id     = $result->getInsertedId();

        return [
            'row' => null,
            'id'  => is_object($id) ? (string) $id : $id,
        ];
    }

    public function updateOne(string $class, array $data, array $id): array
    {
        $meta = Metadata::for($class);

        // $set: partial update — exactly the EM's changed-columns diff.
        $this->collection($meta)->updateOne(
            self::prepareFilter($id),
            ['$set' => $data],
        );

        return ['row' => null, 'id' => null];
    }

    public function upsertOne(
        string $class,
        array $data,
        ?ConflictTarget $on = null,
        ?array $updateColumns = null,
    ): array {
        $meta = Metadata::for($class);
        $pk   = self::pkName($meta);

        // The filter IS the conflict target — mongo resolves insert-vs-update
        // against it atomically (upsert:true), the same role ON CONFLICT plays
        // on SQL. With no explicit target it is the PK; with one it is that
        // key instead, which is what lets a document upsert on a natural key
        // rather than on a driver-generated _id.
        if ($on !== null) {
            $filter = array_intersect_key($data, array_flip($on->columns));

            // An omitted target column cannot be addressed (mongo would treat
            // the missing field as a match on anything), so refuse loudly —
            // the EM normally rejects this first.
            if (array_diff($on->columns, array_keys($filter)) !== []) {
                throw new \LogicException(
                    "Cannot upsert {$class} on '" . implode(', ', $on->columns) . "': the payload is missing a target column."
                );
            }

            // $set minus the target (identity is not reassigned).
            $set = array_diff_key($data, $filter);
        } else {
            // No key = no conflict target: degrade to a plain insert instead
            // of filtering on `_id => null` (which would match every doc
            // without an _id and overwrite one of them).
            if ($meta['pkFields'] === []) {
                return $this->insertOne($class, $data);
            }

            $filter = self::prepareFilter([$pk => $data[$pk] ?? null]);

            $set = $data;
            unset($set[$pk]);
        }

        if ($updateColumns !== null) {
            $set = array_intersect_key($set, array_flip($updateColumns));
        }

        // Nulls are dropped from the assignment payload, exactly as the SQL
        // store does upstream: "not set" must mean "keep whatever the insert
        // wrote", not "overwrite with null".
        $set = array_filter($set, fn($v) => $v !== null);

        // A target-only conflict has nothing left to assign; $setOnInsert
        // keeps the intent ("insert if absent, change nothing otherwise")
        // without emitting an empty $set.
        if ($set === []) {
            $insert = $data;
            // Same rule as insertOne(): an unset _id means "let the driver
            // mint the ObjectId", so a null must not reach the document.
            if ($pk === '_id' && ($insert[$pk] ?? null) === null) {
                unset($insert[$pk]);
            }
            $update = ['$setOnInsert' => $insert];
        } else {
            $update = ['$set' => $set];
        }

        $result = $this->collection($meta)->updateOne($filter, $update, ['upsert' => true]);

        // Backfill: server-generated ids on the upsert-miss path land in
        // getUpsertedId() (the driver mints the ObjectId server-side); the
        // hit path already knows its id.
        $upserted = null;
        if (method_exists($result, 'getUpsertedId')) {
            $upserted = $result->getUpsertedId();
        }

        return [
            'row' => null,
            'id'  => $upserted !== null ? (is_object($upserted) ? (string) $upserted : $upserted) : null,
        ];
    }

    public function deleteOne(string $class, array $id): void
    {
        $meta = Metadata::for($class);
        $this->collection($meta)->deleteOne(self::prepareFilter($id));
    }

    public function findBy(string $class, array $where): array
    {
        $meta = Metadata::for($class);
        // CursorInterface is iterable — no ->toArray() coupling; works for
        // the real driver cursor and the test fake alike.
        return iterator_to_array($this->collection($meta)->find(self::prepareFilter($where)));
    }

    public function findByPk(string $class, array $id): ?array
    {
        $meta = Metadata::for($class);
        $doc  = $this->collection($meta)->findOne(self::prepareFilter($id));

        return $doc === null ? null : (array) $doc;
    }

    public function count(string $class, array $where = []): int
    {
        $meta = Metadata::for($class);
        return $this->collection($meta)->countDocuments(self::prepareFilter($where));
    }

    /* --------------------------------------------------- transactions */

    /**
     * No-ops: multi-document ACID needs replica-set sessions (deferred).
     * Kept structural so the EM pipeline never branches on store type.
     * $meta ignored — an owning store has exactly ONE fixed write target,
     * so there is nothing to address per class.
     */
    public function begin(?array $meta = null): void {}

    public function commit(?array $meta = null): void {}

    public function rollback(?array $meta = null): void {}

    public function inTransaction(?array $meta = null): bool
    {
        return false;
    }

    /* --------------------------------------------- metadata enrichment */

    /**
     * Contribute document-specific metadata during compile:
     *
     * - pkMode = 'convention': documents resolve their PK via the id/*_id
     *   NAME convention (not the SQL Model chain) — this is what keeps
     *   `_id` resolving as the PK. Model-ness alone cannot decide (mongo
     *   documents may extend Model too); only the store knows.
     * - castExclusions = ['json', 'pgarray', 'datetime']: the wire formats
     *   BSON owns natively — the driver maps PHP arrays and
     *   DateTimeInterface itself, so those casts are SUPPRESSED by default
     *   (metadata 'cast' => false per column; #[Column(cast: true)] forces
     *   one back). Scalar casts (int/float/bool) stay ACTIVE: their decode
     *   is a no-op on native BSON values, and custom user-registered casts
     *   keep working unless explicitly opted out.
     * - #[Connection] rejected: this store OWNS its client (the inverse
     *   of PdoStore's borrow model) — multiple mongo connections are
     *   modeled as multiple registered store types ('mongo-eu', …),
     *   selected by #[Entity(store: ...)].
     *
     * Collection resolution stays generic: metadata `source` (#[Entity(name)])
     * with the snake/plural convention as fallback — no per-backend key.
     */
    public function enrichMetadata(array $meta, \ReflectionClass $class): array
    {
        $conn = $class->getAttributes(\Azera\Orm\Attribute\Connection::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($conn !== []) {
            throw new \LogicException(
                "#[Connection] on {$class->name} is not valid with a MongoStore: it owns its " .
                    'connections — register a per-client store type (e.g. setStore(\'mongo-eu\', …)) ' .
                    'and select it via #[Entity(store: \'mongo-eu\')]'
            );
        }

        $meta['pkMode']         = 'convention';
        $meta['castExclusions'] = ['json', 'datetime'];

        return $meta;
    }

    /* -------------------------------------------------------- helpers */

    /**
     * Per-class collection, resolved once per class per store instance.
     * Name: metadata `source` (#[Entity(name)]) — the SAME generic
     * data-location key SQL uses — falling back to the SQL-style
     * snake/plural convention when the attribute omits it.
     *
     * Deliberately NO return type: MongoDB\Collection is final (no
     * interface), so the resolver-seam fake (tests) duck-types on the same
     * method surface — production passes the real object.
     */
    private function collection(array $meta)
    {
        $class = $meta['class'];

        if (isset($this->collections[$class])) {
            return $this->collections[$class];
        }

        $name = $meta['source'] ?? null;
        if ($name === null || $name === '') {
            $short = (new \ReflectionClass($class))->getShortName();
            $name  = ModelMapping::convertModelToSource($short);
        }

        $collection = $this->resolver !== null
            ? ($this->resolver)($name)
            : $this->client->selectCollection($this->database, $name);

        return $this->collections[$class] = $collection;
    }

    /**
     * No transactions: one connection per store instance, so the identity
     * token is constant. begin()/commit()/rollback() are no-ops anyway.
     */
    public function txTarget(array $meta): string
    {
        return 'mongo:' . $meta['store'];
    }

    /**
     * The document's PK column name. Metadata resolves this for documents
     * (the id/*_id name convention plus #[Column(pk:)] marks); the `_id`
     * fallback only applies to a class with no key metadata at all — the
     * same case where `Metadata::pkFields` falls back to `['id']`.
     */
    private static function pkName(array $meta): string
    {
        foreach ($meta['columns'] as $col) {
            if ($col['pk']) {
                return $col['name'];
            }
        }

        return '_id';
    }

    /**
     * Filter preparation: THE _id TYPE CONTRACT. The server stores an
     * ObjectId (the driver generates one when insert omits _id), but the
     * EM round-trips the STRING backfill (insertOne returns (string) id →
     * entity → node->id → next filter). Mongo matches by TYPE: a string
     * filter against an ObjectId doc matches NOTHING — updates/deletes
     * silently no-op and the follow-up save even re-INSERTs (duplicate).
     * Fix at the store boundary: any 24-hex-char string _id IS an ObjectId
     * backfill → cast back for the wire. Non-matching strings (caller-set
     * custom ids) and ObjectIds pass through untouched.
     */
    private static function prepareFilter(array $filter): array
    {
        if (!isset($filter['_id']) || !\is_string($filter['_id'])) {
            return $filter;
        }

        $id = $filter['_id'];
        if (preg_match('/^[0-9a-fA-F]{24}$/', $id) === 1) {
            $filter['_id'] = new \MongoDB\BSON\ObjectId($id);
        }

        return $filter;
    }
}