<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/Fixtures/NaturalKeyRow.php';
require_once __DIR__ . '/FakeMongoCollection.php';
require_once __DIR__ . '/Fixtures/ArticleDocument.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Orm\ConflictTarget;
use Azera\Orm\EntityManager;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\MongoStore;
use Azera\Orm\Storage\PdoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use Azera\Tests\Orm\Fixtures\ArticleDocument;
use Azera\Tests\Orm\Fixtures\NaturalKeyRow;
use PHPUnit\Framework\TestCase;

/**
 * An UPSERT must be able to conflict on a key OTHER than the primary key.
 *
 * Two shapes need it, and the old PK-only target served neither:
 *
 * - a database-generated PK (a uuid the caller has not got before the row
 *   exists — `upsert()` degraded to a plain INSERT, so it was not idempotent
 *   at all), and
 * - a natural unique key, where the PK is incidental. `stripe_customer_id` is
 *   the case that produced the production webhook 500: the read-then-insert in
 *   the application layer raced, and the framework had no way to say "conflict
 *   on stripe_customer_id" so the database could resolve it atomically.
 *
 * A PARTIAL unique index additionally requires the statement to repeat the
 * index predicate; PostgreSQL infers an index from the target alone and
 * refuses the statement otherwise ("there is no unique or exclusion constraint
 * matching the ON CONFLICT specification").
 */
class UpsertConflictTargetTest extends TestCase
{
    private TestDatabase $db;
    private AppContext $ctx;
    private EntityManager $em;
    private PdoStore $store;

    protected function setUp(): void
    {
        $this->db  = new TestDatabase('pgsql');
        $this->ctx = new AppContext();
        AppContext::setInstance($this->ctx);
        FastHydrator::clear();
        Metadata::clear();

        $dbm = new DatabaseManager();
        $dbm->set('default', $this->db);
        $dbm->set('read', $this->db);
        $dbm->set('write', $this->db);
        $this->ctx->set(DatabaseManager::class, $dbm);

        $this->store = new PdoStore($dbm, 'read', 'write');

        $stores = new Stores();
        $stores->set('sql', $this->store);
        $this->ctx->set(Stores::class, $stores);

        $this->em = $this->ctx->entityManager();
    }

    protected function tearDown(): void
    {
        AppContext::reset();
    }

    /** @return list<array{sql: string, params: mixed}> */
    private function dataQueries(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    private function lastDataSql(?TestDatabase $db = null): string
    {
        $db      = $db ?? $this->db;
        $queries = array_values(array_filter(
            $db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));

        return $queries === [] ? '' : $queries[count($queries) - 1]['sql'];
    }

    /** A payload with no PK set — the database generates it. */
    private function naturalKeyRow(): NaturalKeyRow
    {
        $row                     = new NaturalKeyRow();
        $row->stripe_customer_id = 'cus_Vi1M5MDMx33UH6';
        $row->email              = 'kunde7@simsaai.com';
        $row->name               = 'Acme';
        $row->id                 = null;

        return $row;
    }

    /* ------------------------------------------------- the defect */

    /**
     * Without a target, an unset PK means "no identity to promise" and the
     * call degrades to an INSERT — so a second delivery inserts a duplicate
     * row instead of updating the first. That is the shape the webhook hit.
     */
    public function testWithoutATargetTheUpsertDegradesToAPlainInsert(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert($row);
        $this->em->flushEntity($row);

        $sql = $this->lastDataSql();

        $this->assertStringStartsWith('INSERT INTO "natural_key_row"', $sql);
        $this->assertStringNotContainsString(
            'ON CONFLICT',
            $sql,
            'an unset PK leaves nothing to conflict on, so the statement is a plain INSERT'
        );
    }

    /**
     * With an explicit target the same unset PK is fine: the conflict target
     * is the natural key, not the generated id.
     */
    public function testAnExplicitTargetConflictsOnThatKeyAndNotThePrimaryKey(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert($row, ConflictTarget::columns(['stripe_customer_id']));
        $this->em->flushEntity($row);

        $sql = $this->lastDataSql();

        $this->assertStringContainsString(
            'ON CONFLICT ("stripe_customer_id") DO UPDATE SET',
            $sql,
            'the target names the natural key'
        );
        $this->assertStringNotContainsString(
            'ON CONFLICT ("id")',
            $sql,
            'the generated PK is not the identity the caller asserted'
        );
    }

    /** The target is identity: it is never reassigned in DO UPDATE SET. */
    public function testTheTargetColumnIsNeverReassigned(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert($row, ConflictTarget::columns(['stripe_customer_id']));
        $this->em->flushEntity($row);

        $sql = $this->lastDataSql();

        $this->assertStringNotContainsString(
            '"stripe_customer_id" = EXCLUDED."stripe_customer_id"',
            $sql,
            'a target column in DO UPDATE SET makes SQLite compile a DELETE+INSERT'
        );
        $this->assertStringContainsString('"email" = EXCLUDED."email"', $sql);
    }

    /* -------------------------------------------- partial indexes */

    /**
     * A partial unique index is only inferred when the statement repeats its
     * predicate. Verified against PostgreSQL 18: dropping the predicate fails
     * with "no unique or exclusion constraint matching the ON CONFLICT
     * specification".
     */
    public function testAPartialIndexPredicateIsEmittedInTheConflictClause(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert($row, ConflictTarget::columns(
            ['stripe_customer_id'],
            'stripe_customer_id IS NOT NULL',
        ));
        $this->em->flushEntity($row);

        $this->assertStringContainsString(
            'ON CONFLICT ("stripe_customer_id") WHERE stripe_customer_id IS NOT NULL DO UPDATE SET',
            $this->lastDataSql(),
        );
    }

    public function testAFullIndexEmitsNoPredicate(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert($row, ConflictTarget::columns(['stripe_customer_id']));
        $this->em->flushEntity($row);

        $this->assertStringNotContainsString(' WHERE ', $this->lastDataSql());
    }

    /* ------------------------------------------------ target promise */

    /**
     * A null target value can never match (NULL is not equal to NULL), so the
     * upsert would insert a duplicate while reporting success. Refuse it
     * before any statement runs, and name the property the caller should fix.
     */
    public function testANullTargetValueIsRefusedRatherThanSilentlyInserting(): void
    {
        $row                     = $this->naturalKeyRow();
        $row->stripe_customer_id = null;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/property \$stripe_customer_id/');

        $this->em->upsert($row, ConflictTarget::columns(['stripe_customer_id']));
    }

    /** A column the model does not persist is a different mistake. */
    public function testATargetColumnTheModelDoesNotPersistIsNamed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches("/no persisted column named 'stripe_customer_ref'/");

        $this->em->upsert($this->naturalKeyRow(), ConflictTarget::columns(['stripe_customer_ref']));
    }

    /** An empty target is meaningless — fail at construction, not at flush. */
    public function testAnEmptyTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConflictTarget::columns([]);
    }

    /**
     * The predicate is emitted verbatim, so a placeholder or a statement
     * terminator is a bug at the call site — caught at construction rather than
     * producing a broken or injected statement at flush.
     */
    public function testAPredicateWithAPlaceholderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConflictTarget::columns(['stripe_customer_id'], 'stripe_customer_id = ?');
    }

    public function testAPredicateWithAStatementTerminatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConflictTarget::columns(['stripe_customer_id'], 'stripe_customer_id IS NOT NULL; DROP TABLE x');
    }

    /* ------------------------------------------ whole-row in the target */

    /**
     * When the payload carries nothing outside the target there is no SET
     * list to emit, and `DO UPDATE SET` with an empty list is a syntax error.
     * "The row already exists" is the honest statement.
     */
    public function testATargetCoveringEveryColumnBecomesDoNothing(): void
    {
        $this->db->setMockResults([[]]);

        $this->store->upsertOne(
            NaturalKeyRow::class,
            ['stripe_customer_id' => 'cus_1'],
            ConflictTarget::columns(['stripe_customer_id']),
        );

        $sql = $this->lastDataSql();

        $this->assertStringContainsString('ON CONFLICT ("stripe_customer_id") DO NOTHING', $sql);
        $this->assertStringNotContainsString('DO UPDATE SET', $sql);
    }

    /* ------------------------------------------ narrowing the action */

    /**
     * A wide row must not be clobbered by a raced insert. `$updateColumns`
     * restricts what the conflict action writes, so state the caller does not
     * own (here: a credential revision that the default 0 would silently
     * reset) survives.
     */
    public function testUpdateColumnsRestrictsWhatTheConflictActionWrites(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert(
            $row,
            ConflictTarget::columns(['stripe_customer_id']),
            ['email'],
        );
        $this->em->flushEntity($row);

        $sql = $this->lastDataSql();

        $this->assertStringContainsString('"email" = EXCLUDED."email"', $sql);
        $this->assertStringNotContainsString(
            '"name" = EXCLUDED."name"',
            $sql,
            'a column outside $updateColumns must not be reassigned on conflict'
        );
    }

    /**
     * `[]` is "insert if absent, change nothing" — the honest rendering of an
     * upsert whose only interest is the row existing.
     */
    public function testAnEmptyUpdateColumnsListBecomesDoNothing(): void
    {
        $row = $this->naturalKeyRow();
        $this->em->upsert($row, ConflictTarget::columns(['stripe_customer_id']), []);
        $this->em->flushEntity($row);

        $sql = $this->lastDataSql();

        $this->assertStringContainsString('ON CONFLICT ("stripe_customer_id") DO NOTHING', $sql);
        $this->assertStringNotContainsString('EXCLUDED.', $sql);
    }

    /* ------------------------------------------------------ dialects */

    /**
     * MySQL's ON DUPLICATE KEY UPDATE has no target clause: it fires on ANY
     * violated unique index. Honouring an explicit target is impossible, and
     * updating whichever row happened to collide is worse than failing.
     */
    public function testMySqlRefusesAnExplicitTarget(): void
    {
        $db = new TestDatabase('mysql');
        $this->ctx->dbManager()->set('write', $db);
        $this->ctx->dbManager()->set('read', $db);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/MySQL/');

        $this->store->upsertOne(
            NaturalKeyRow::class,
            ['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com'],
            ConflictTarget::columns(['stripe_customer_id']),
        );
    }

    /** The PK path is unchanged on MySQL (no target, existing shape). */
    public function testMySqlStillUpsertsOnThePrimaryKey(): void
    {
        $db = new TestDatabase('mysql');
        $this->ctx->dbManager()->set('write', $db);
        $this->ctx->dbManager()->set('read', $db);

        $this->store->upsertOne(NaturalKeyRow::class, ['id' => 'abc', 'email' => 'a@example.com']);

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $this->lastDataSql($db));
    }

    /* --------------------------------------------------- backfill */

    /**
     * A generated PK arrives via RETURNING *, and it must be refreshed even
     * though the PK is NOT the conflict target on this path — otherwise the
     * entity keeps a null id and the next save() inserts again.
     */
    public function testAGeneratedKeyIsBackfilledOnTheExplicitTargetPath(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';
        $this->db->setMockResults([[['id' => $uuid, 'stripe_customer_id' => 'cus_1', 'email' => 'a@example.com', 'name' => 'A']]]);

        $result = $this->store->upsertOne(
            NaturalKeyRow::class,
            ['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com'],
            ConflictTarget::columns(['stripe_customer_id']),
        );

        $this->assertStringContainsString('RETURNING *', $this->lastDataSql());
        $this->assertSame($uuid, $result['row']['id'] ?? null);
    }

    /* ----------------------------------------------------- facade */

    /** The facade forwards the target, so call sites do not touch the EM. */
    public function testTheFacadeForwardsTheTarget(): void
    {
        $row = NaturalKeyRow::upsert(
            ['stripe_customer_id' => 'cus_42', 'email' => 'a@example.com', 'name' => 'A'],
            ConflictTarget::columns(['stripe_customer_id'], 'stripe_customer_id IS NOT NULL'),
        );

        $this->assertInstanceOf(NaturalKeyRow::class, $row);
        $this->assertStringContainsString(
            'ON CONFLICT ("stripe_customer_id") WHERE stripe_customer_id IS NOT NULL DO UPDATE SET',
            $this->lastDataSql(),
        );
    }

    /* ------------------------------------------------------ mongo */

    /**
     * The mongo twin: the filter IS the conflict target, and the payload minus
     * the target is the $set — so a document upserts on a natural key without
     * a driver-generated _id to name.
     */
    public function testMongoUpsertsOnAnExplicitTargetInsteadOfThePk(): void
    {
        $fakes = new FakeMongoFactory();
        AppContext::instance()->entityManager()->setStore('mongo', new MongoStore(fn(string $name) => $fakes->for($name)));
        Metadata::clear();

        $store = new MongoStore(fn(string $name) => $fakes->for($name));

        $store->upsertOne(
            ArticleDocument::class,
            ['title' => 'Hello', 'tags' => []],
            ConflictTarget::columns(['title']),
        );

        $calls = $fakes->for('articles')->calls;
        $call  = $calls[count($calls) - 1];

        $this->assertSame(['title' => 'Hello'], $call['args'][0], 'the filter is the target');
        $this->assertSame(['$set' => ['tags' => []]], $call['args'][1], 'the target is not in $set');
        $this->assertTrue($call['args'][2]['upsert']);
    }

    /** A target-only payload inserts without emitting an empty $set. */
    public function testMongoATargetOnlyPayloadUsesSetOnInsert(): void
    {
        $fakes = new FakeMongoFactory();
        AppContext::instance()->entityManager()->setStore('mongo', new MongoStore(fn(string $name) => $fakes->for($name)));
        Metadata::clear();

        $store = new MongoStore(fn(string $name) => $fakes->for($name));

        $store->upsertOne(ArticleDocument::class, ['title' => 'Hello'], ConflictTarget::columns(['title']));

        $calls = $fakes->for('articles')->calls;
        $call  = $calls[count($calls) - 1];

        $this->assertSame(['$setOnInsert' => ['title' => 'Hello']], $call['args'][1]);
    }

    /**
     * A null _id must not reach the document: it means "let the driver mint the
     * ObjectId", the same rule insertOne() follows.
     */
    public function testMongoDoesNotPersistANullIdOnTheSetOnInsertPath(): void
    {
        $fakes = new FakeMongoFactory();
        AppContext::instance()->entityManager()->setStore('mongo', new MongoStore(fn(string $name) => $fakes->for($name)));
        Metadata::clear();

        $store = new MongoStore(fn(string $name) => $fakes->for($name));

        $store->upsertOne(
            ArticleDocument::class,
            ['_id' => null, 'title' => 'Hello'],
            ConflictTarget::columns(['title']),
        );

        $calls = $fakes->for('articles')->calls;
        $call  = $calls[count($calls) - 1];

        $this->assertSame(['title' => 'Hello'], $call['args'][1]['$setOnInsert'] ?? null);
        $this->assertArrayNotHasKey('_id', $call['args'][1]['$setOnInsert'] ?? []);
    }

    /**
     * `$updateColumns` narrows the mongo $set too, so a natural-key upsert
     * cannot clobber fields the caller does not own.
     */
    public function testMongoHonoursUpdateColumns(): void
    {
        $fakes = new FakeMongoFactory();
        AppContext::instance()->entityManager()->setStore('mongo', new MongoStore(fn(string $name) => $fakes->for($name)));
        Metadata::clear();

        $store = new MongoStore(fn(string $name) => $fakes->for($name));

        $store->upsertOne(
            ArticleDocument::class,
            ['title' => 'Hello', 'tags' => ['a']],
            ConflictTarget::columns(['title']),
            ['tags'],
        );

        $calls = $fakes->for('articles')->calls;
        $call  = $calls[count($calls) - 1];

        $this->assertSame(['$set' => ['tags' => ['a']]], $call['args'][1]);
    }
}
