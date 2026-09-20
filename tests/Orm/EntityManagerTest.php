<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/Fixtures/Relations.php';
require_once __DIR__ . '/Fixtures/AuditEntry.php';
require_once __DIR__ . '/Fixtures/InternalProperties.php';
require_once __DIR__ . '/FakeMongoCollection.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Db\Database;
use Azera\Orm\EntityManager;
use Azera\Orm\FastHydrator;
use Azera\Orm\Heap;
use Azera\Orm\Metadata;
use Azera\Orm\Node;
use Azera\Orm\Storage\MongoStore;
use Azera\Orm\Storage\PdoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use Azera\Tests\Orm\Fixtures\Article;
use Azera\Tests\Orm\Fixtures\AuditEntry;
use Azera\Tests\Orm\Fixtures\Author;
use Azera\Tests\Orm\Fixtures\Comment;
use Azera\Tests\Orm\Fixtures\InternalProperties;
use PHPUnit\Framework\TestCase;

/** Document base-class fixture: exercises the Document facade (not Model). */
#[\Azera\Orm\Attribute\Entity(store: 'mongo', name: 'docs')]
class DocumentFixture extends \Azera\Orm\Document
{
    public $_id;
    public $title;
    public $tags;
}

class EntityManagerTest extends TestCase
{
    private TestDatabase $db;
    private AppContext $ctx;
    private EntityManager $em;
    protected function setUp(): void
    {
        $this->db  = new TestDatabase('pgsql');
        $this->ctx = new AppContext();
        AppContext::setInstance($this->ctx);
        FastHydrator::clear();
        Metadata::clear();

        // Wire the default DatabaseManager role to the test DB so PdoStore
        // borrows it (same pattern as the app bootstrap).
        $dbm = new DatabaseManager();
        $dbm->set('default', $this->db);
        $dbm->set('read', $this->db);
        $dbm->set('write', $this->db);
        $this->ctx->set(DatabaseManager::class, $dbm);

        $stores = new Stores();
        $stores->set('sql', new PdoStore($dbm, 'read', 'write'));
        $this->ctx->set(Stores::class, $stores);

        $this->em = $this->ctx->entityManager();
    }

    protected function tearDown(): void
    {
        AppContext::reset();
    }

    /**
     * TestDatabase also logs BEGIN/COMMIT — filter them out.
     */
    private function dataQueries(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    private function articleRow(int $id, string $title = 'T'): array
    {
        return ['id' => (string) $id, 'title' => $title, 'created_at' => null, 'status_code' => '0'];
    }

    private function lastDataSql(): ?string
    {
        $q = $this->dataQueries();
        return $q === [] ? null : end($q)['sql'];
    }

    /* ================================================== upsert pipeline
     *
     * SCHEDULED_UPSERT: one atomic INSERT ... ON CONFLICT DO UPDATE — the
     * DATABASE resolves insert-vs-update (no SELECT, no unique-violation
     * race). PK = conflict target; DO UPDATE SET covers NON-PK columns
     * only (the fast excluded-refs shape). */

    /**
     * Full-payload upsert (PK + all non-PK set): one statement, ON CONFLICT
     * target = "id", SET writes non-PK columns via EXCLUDED refs. All
     * columns present → nothing to backfill → no RETURNING (the fastest
     * shape).
     */
    public function testUpsertEmitsOnConflictWithNonPkExcludedSet(): void
    {
        $a = new Article();
        $a->id         = 999999;
        $a->title      = 'Sentinel';
        $a->status     = 1;
        $a->created_at = '2026-09-05 00:00:00';

        $this->em->upsert($a);
        $this->em->flush();

        $sql = $this->lastDataSql();
        $this->assertStringStartsWith('INSERT INTO "article"', $sql);
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $sql);
        $this->assertStringContainsString('"title" = EXCLUDED."title"', $sql);
        $this->assertStringContainsString('"status_code" = EXCLUDED."status_code"', $sql);
        $this->assertStringNotContainsString('"id" = EXCLUDED."id"', $sql); // PK never in SET
        $this->assertStringNotContainsString('RETURNING', $sql);
        $this->assertSame(Node::MANAGED, $this->heapNode($a)->state);
    }

    /**
     * Unset non-PK columns: RETURNING * refreshes DB defaults onto the
     * entity (mirrors insertOne's returning_all strategy).
     */
    public function testUpsertMissingColumnsUsesReturningAllAndBackfills(): void
    {
        $a = new Article();
        $a->id    = 999999;
        $a->title = 'Sentinel';
        // created_at / status unset → DB defaults must round-trip.
        $this->db->setMockResults([[['id' => '999999', 'title' => 'Sentinel', 'created_at' => '2026-09-05 00:00:00', 'status_code' => '0']]]);

        $this->em->upsert($a);
        $this->em->flush();

        $this->assertStringContainsString('RETURNING *', $this->lastDataSql());
        // RETURNING * rows decode 'datetime' columns onto the entity.
        $this->assertInstanceOf(\DateTimeImmutable::class, $a->created_at);
        $this->assertSame('2026-09-05 00:00:00', $a->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(Node::MANAGED, $this->heapNode($a)->state);
    }

    /**
     * The upsert statement joins the flush transaction like every other
     * scheduled write (BEGIN ... COMMIT wrap).
     */
    public function testUpsertJoinsFlushTransaction(): void
    {
        $a = new Article();
        $a->id    = 999999;
        $a->title = 'Sentinel';

        $this->em->upsert($a);
        $this->em->flush();

        $this->assertStringStartsWith('BEGIN', $this->db->queries[0]['sql']);
        $this->assertStringStartsWith('COMMIT', $this->db->getLastSql());
    }

    /**
     * Upsert marks the entity MANAGED in the identity map: a follow-up
     * mutate + save() emits a diff UPDATE, not a second upsert.
     */
    public function testUpsertedEntityBecomesManagedAndSavesAsUpdate(): void
    {
        $a = new Article();
        $a->id    = 999999;
        $a->title = 'Sentinel';

        $this->em->upsert($a);
        $this->em->flush();
        $this->db->clearQueries();

        $a->title = 'Mutated';
        $this->assertTrue($a->save());

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('UPDATE "article"', $q[0]['sql']);
        $this->assertStringNotContainsString('ON CONFLICT', $q[0]['sql']);
    }

    /**
     * Mongo routing: upsert on a document hits the mongo store's
     * updateOne(filter, $set, upsert:true) — never SQL.
     */
    public function testUpsertOnDocumentRoutesThroughMongoStore(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));

        $doc = new \Azera\Tests\Orm\Fixtures\ArticleDocument();
        $doc->_id   = 'abc123';
        $doc->title = 'Doc One';

        $this->em->upsert($doc);
        $this->em->flush();

        $articles = $fakes->for('articles');
        $this->assertCount(1, $articles->calls);
        $this->assertSame('update', $articles->calls[0]['op']);
        [$filter, $update, $options] = $articles->calls[0]['args'];
        $this->assertSame(['_id' => 'abc123'], $filter);
        $this->assertSame(['title' => 'Doc One'], $update['$set']); // PK excluded from $set
        $this->assertTrue($options['upsert']);
        $this->assertSame('Doc One', $articles->docs[0]['title']);
        $this->assertSame(Node::MANAGED, $this->heapNode($doc)->state);
    }

    private function heapNode(object $entity): Node
    {
        $node = $this->em->heap()->find($entity);
        $this->assertNotNull($node);
        return $node;
    }

    /**
     * Fresh AppContext (no stores registered) + non-sql metadata → the
     * actionable unregistered-type error. Also proves no static bleed:
     * stores registered in earlier tests must NOT leak into a fresh
     * context (the Stores holder is context-attached, not process-global).
     */
    public function testUnregisteredStoreTypeThrowsActionablyInFreshContext(): void
    {
        // Simulate a fresh worker process: brand-new context + L1 reset.
        $ctx = new AppContext();
        AppContext::setInstance($ctx);
        Metadata::clear();

        $em = $ctx->entityManager();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("No store registered for type 'mongo'");
            $em->find(\Azera\Tests\Orm\Fixtures\ArticleDocument::class, ['_id' => 'x']);
        } finally {
            AppContext::setInstance($this->ctx); // restore for tearDown
        }
    }

    /**
     * PK set at insert time = nothing to backfill → plain INSERT, no
     * RETURNING clause (the per-backend strategy matrix in PdoStore).
     */
    public function testInsertWithPkSetUsesPlainInsertWithoutReturning(): void
    {
        $a = new Article();
        $a->id    = 42;
        $a->title = 'Sentinel';

        $this->em->persist($a);
        $this->em->flush();

        $sql = $this->lastDataSql();
        $this->assertStringStartsWith('INSERT INTO "article"', $sql);
        $this->assertStringNotContainsString('RETURNING', $sql);
    }

    /**
     * Auto-increment (PK omitted): smallest-payload INSERT + RETURNING "id",
     * and the generated id is backfilled onto the entity.
     */
    public function testInsertAutoIncrementUsesReturningIdAndBackfills(): void
    {
        $a = new Article();
        $a->title      = 'Auto';
        $a->created_at = '2026-09-03 10:00:00';
        $a->status     = 1;
        // all non-PK columns set, only id missing -> smallest payload
        $this->db->setMockResults([[['id' => 5]]]);

        $this->em->persist($a);
        $this->em->flush();

        $this->assertStringContainsString('RETURNING "id"', $this->lastDataSql());
        $this->assertSame(5, $a->id);
    }

    /**
     * Some non-PK columns left unset: fallback strategy RETURNING * so
     * DB defaults land back on the entity.
     */
    public function testInsertMissingColumnsFallsBackToReturningAll(): void
    {
        $a = new Article();
        $a->title = 'Auto';
        $this->db->setMockResults([[['id' => 5, 'title' => 'Auto', 'status_code' => 0]]]);

        $this->em->persist($a);
        $this->em->flush();

        $this->assertStringContainsString('RETURNING *', $this->lastDataSql());
    }

    /**
     * A class carrying static + readonly properties must round-trip
     * without a single PHP notice/error: both shapes are excluded from
     * the column set, so no instance-style read/write touches them.
     *
     * Regression guard: before the compiler guards, a static column made
     * extractData() emit "Accessing static property ... as non static" on
     * every write, a readonly column made backfill() throw
     * "Cannot initialize readonly property ... from scope EntityManager"
     * AFTER the row had already been inserted, and a non-public column
     * threw "Cannot access protected property ..." during hydration.
     */
    public function testStaticAndReadonlyPropertiesAreIgnoredAcrossFullRoundTrip(): void
    {
        $notices = [];
        set_error_handler(function (int $no, string $msg) use (&$notices): bool {
            $notices[] = $msg;
            return true; // swallow: we assert on it below
        });

        try {
            InternalProperties::$instances = 7;

            $e = new InternalProperties();
            $e->title = 'Round Trip';
            // Present on the entity but NOT a column — must never be written.
            $e->__cached  = 'internal-only';
            $e->_revision = 'r1';

            // RETURNING * exercises applyRow()/backfill() — the site that
            // previously threw on a readonly column.
            $this->db->setMockResults([
                [
                    [
                        'id'        => 5,
                        'title'     => 'Round Trip',
                        '_revision' => 'r1',
                        '__cached'  => 'internal-only',
                    ]
                ],
            ]);

            $this->em->persist($e);
            $this->em->flush();

            // INSERT carries the real columns only — never the excluded
            // shapes, never the opted-out ones.
            $insert = $this->dataQueries()[0]['sql'];
            $this->assertStringNotContainsString('instances', $insert);
            $this->assertStringNotContainsString('immutable', $insert);
            $this->assertStringNotContainsString('label', $insert);
            $this->assertStringNotContainsString('derived', $insert);
            $this->assertStringNotContainsString('protectedState', $insert);
            $this->assertStringNotContainsString('privateState', $insert);

            // Backfill landed the generated PK on the entity.
            $this->assertSame(5, $e->id);

            // Excluded properties kept their own values untouched.
            $this->assertSame(7, InternalProperties::$instances);
            $this->assertSame('internal-only', $e->__cached);
            $this->assertSame('set-once', $e->immutable);
            $this->assertSame('derived-label', $e->label);
            $this->assertSame('p', $e->protectedState());

            // Underscore-prefixed properties ARE columns, so they hydrate.
            $this->assertSame('r1', $e->_revision);

            $this->assertSame(
                [],
                array_values(array_filter(
                    $notices,
                    fn(string $m) =>
                        str_contains($m, 'Static') || str_contains($m, 'static property')
                )),
                'no static-property notices during the round trip'
            );
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The fresh-read path re-applies a row onto a tracked instance
     * (FastHydrator::apply) — the second-write that readonly cannot
     * survive. Excluded shapes must stay out of it.
     */
    public function testRefreshInPlaceSkipsStaticsAndReadonly(): void
    {
        InternalProperties::$instances = 3;

        $this->db->setMockResults([
            [['id' => 5, 'title' => 'First', '_revision' => 'r1', '__cached' => 'a']],
        ]);
        $e = $this->em->find(InternalProperties::class, ['id' => 5]);

        $this->assertSame('First', $e->title);

        $this->db->setMockResults([
            [['id' => 5, 'title' => 'Second', '_revision' => 'r2', '__cached' => 'b']],
        ]);
        $again = $this->em->find(InternalProperties::class, ['id' => 5], fresh: true);

        $this->assertSame($e, $again, 'identity preserved');
        $this->assertSame('Second', $e->title, 'ordinary columns refresh in place');
        $this->assertSame('r2', $e->_revision, 'underscore columns refresh in place');
        $this->assertSame(3, InternalProperties::$instances, 'static untouched');
        $this->assertSame('set-once', $e->immutable, 'readonly untouched');
    }

    /**
     * BelongsTo owner flushes before dependent: auto-generated owner PKs
     * can backfill into dependents' FK values (topological order).
     */
    public function testBelongsToOwnerFlushesBeforeDependent(): void
    {
        $this->db->setMockResults([
            [['id' => 9]], // owner insert RETURNING
            [['id' => 9]]  // (unused second)
        ]);

        $author = new Author();
        $author->name = 'X';

        $c = new Comment();
        $c->author_id = null;
        $c->body      = 'B';

        $this->em->persist($author);
        $this->em->persist($c);
        $this->em->flush();

        $q = $this->dataQueries();
        $this->assertCount(2, $q);
        $this->assertStringStartsWith('INSERT INTO "author"', $q[0]['sql']);
        $this->assertStringStartsWith('INSERT INTO "comment"', $q[1]['sql']);
    }

    /**
     * Regression: two auto-increment INSERTs scheduled in one flush used to
     * collapse (both nodes keyed identically while their PKs were unset),
     * silently dropping the first INSERT. Each scheduled PK-less entity
     * must produce its own INSERT and its own id backfill.
     */
    public function testBatchedAutoIncrementInsertsAllExecuteAndBackfill(): void
    {
        $this->db->setMockResults([
            [['id' => '1']], // first insert RETURNING id
            [['id' => '2']], // second insert RETURNING id
        ]);

        $a = new Article();
        $a->title = 'First';

        $b = new Article();
        $b->title = 'Second';

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $q = $this->dataQueries();
        $this->assertCount(2, $q);
        $this->assertSame('First', $q[0]['params'][0] ?? null);
        $this->assertSame('Second', $q[1]['params'][0] ?? null);
        // int-cast PK: backfill decodes like hydration (find() coerces too).
        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testPersistFlushInsertsThroughStore(): void
    {
        $a = new Article();
        $a->id    = 42;
        $a->title = 'Sentinel';

        $this->em->persist($a);
        $this->em->flush();

        $q = $this->dataQueries();
        $this->assertNotEmpty($q);
        $this->assertStringStartsWith('INSERT INTO "article"', $q[0]['sql']);
    }

    public function testFindReturnsNullWhenStoreMisses(): void
    {
        $this->db->setMockResults([[]]); // findByPk -> no rows
        $this->assertNull($this->em->find(Article::class, ['id' => 1]));
    }
    public function testFindHydratesOntoHeapAndIsCached(): void
    {
        $row = $this->articleRow(7, 'Seven');
        $this->db->setMockResults([[$row]]);

        $a = $this->em->find(Article::class, ['id' => 7]);
        $this->assertNotNull($a);
        $this->assertSame('Seven', $a->title);

        // Second find: heap hit → NO additional SQL
        $before = count($this->db->queries);
        $again  = $this->em->find(Article::class, ['id' => 7]);
        $this->assertSame($a, $again);
        $this->assertCount($before, $this->db->queries);
    }

    public function testUpdateViaEmWritesOnlyChangedColumns(): void
    {
        // 1) seed load
        $this->db->setMockResults([[$this->articleRow(1, 'One')]]);
        $a = $this->em->find(Article::class, ['id' => 1]);
        $this->assertNotNull($a);
        $this->db->clearQueries();

        $a->title = 'Two';
        $this->em->persist($a);
        $this->em->flush();

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('UPDATE "article"', $q[0]['sql']);
        $this->assertStringContainsString('SET "title" = ?', $q[0]['sql']);
        $this->assertStringNotContainsString('status_code', $q[0]['sql']);
    }

    public function testCleanEntityIsNotWritten(): void
    {
        $this->db->setMockResults([[$this->articleRow(1, 'Same')]]);
        $a = $this->em->find(Article::class, ['id' => 1]);
        $this->assertNotNull($a);
        $this->db->clearQueries();

        $this->em->persist($a);
        $this->em->flush();

        $this->assertSame([], $this->db->queries);
    }

    public function testRemoveDetachesAndDeletes(): void
    {
        $this->db->setMockResults([[$this->articleRow(1, 'Doomed')]]);
        $a = $this->em->find(Article::class, ['id' => 1]);
        $this->assertNotNull($a);
        $this->db->clearQueries();

        $this->em->remove($a);
        $this->em->flush();

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('DELETE FROM "article"', $q[0]['sql']);
        $this->assertStringContainsString('WHERE "id" = ?', $q[0]['sql']);
        $this->assertNull($this->em->heap()->findById(Article::class, ['id' => 1]));
        $this->assertNull($this->em->find(Article::class, ['id' => 1])); // detached — but wait: find re-queries
    }

    public function testClearWipesIdentityAndScheduledWork(): void
    {
        $a = new Article();
        $a->id    = 5;
        $a->title = 'Pending';

        $this->em->persist($a);
        $this->em->clear();

        // Scheduled work is forgotten: flush is a no-op.
        $before = count($this->db->queries);
        $this->em->flush();
        $this->assertCount($before, $this->db->queries);
        $this->assertSame(0, $this->em->heap()->count());
    }

    public function testResetStateClearsHeapAndUow(): void
    {
        $a = new Article();
        $a->id    = 5;
        $a->title = 'Pending';

        $this->em->persist($a);
        $this->em->resetState();

        $this->assertSame(0, $this->em->heap()->count());
    }

    public function testFindSharesHeapWithQueryEntities(): void
    {
        $row = $this->articleRow(9, 'Shared');

        // EM find first (one mock result consumed by findByPk)
        $this->db->setMockResults([[$row]]);
        $viaEm = $this->em->find(Article::class, ['id' => 9]);
        $this->assertNotNull($viaEm);

        // Query entities() on the SAME AppContext heap — identity hit
        // must return the identical object without re-hydrating.
        $this->db->clearQueries();
        $this->db->setMockResults([[$row]]);

        $viaQuery = \Azera\Db\Query::modelFor(Article::class, $this->db)
            ->where('id', '=', 9)
            ->firstEntity();

        $this->assertNotNull($viaQuery);
        $this->assertSame($viaEm, $viaQuery);
    }

    public function testTransactionWrapsFlushViaStore(): void
    {
        $a = new Article();
        $a->id = 3;
        $this->em->persist($a);
        $this->em->flush();

        $this->assertStringStartsWith('BEGIN', $this->db->queries[0]['sql']);
        $this->assertStringStartsWith('COMMIT', $this->db->getLastSql());
    }

    /* ================================================== facade tests
     *
     * Model::save()/delete() and Document::save()/delete() must land in
     * the SAME EM pipeline as EM-direct calls. Facade models are Model
     * subclasses (DirtyState + EM heap-diff engine applies), so these
     * tests exercise the full pipeline: legacy loads → adopt → flush →
     * identical SQL.
     */

    /**
     * Hydrated-then-mutated facade model: save() must produce EXACTLY one
     * UPDATE of the changed column — the heap-diff parity proof (identical
     * SQL to an EM-direct find → mutate → persist → flush).
     */
    public function testModelSaveAfterLoadWritesOnlyChangedColumns(): void
    {
        $row = $this->articleRow(7, 'Seven');
        $this->db->setMockResults([[$row]]);

        $article = Article::find(7);
        $this->assertNotNull($article);
        $this->db->clearQueries();

        $article->title = 'Mutated';
        $this->assertTrue($article->save());

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('UPDATE "article"', $q[0]['sql']);
        $this->assertStringContainsString('SET "title" = ?', $q[0]['sql']);
        $this->assertStringNotContainsString('status_code', $q[0]['sql']);
        $this->assertStringContainsString('WHERE "id" = ?', $q[0]['sql']);
        $this->assertFalse($article->hasChanged());
    }

    /**
     * Same load, no mutation: save() must write NOTHING (clean-entity
     * parity with the EM path).
     */
    public function testModelCleanSaveEmitsNoSql(): void
    {
        $row = $this->articleRow(7, 'Seven');
        $this->db->setMockResults([[$row]]);

        $article = Article::find(7);
        $this->db->clearQueries();

        $this->assertFalse($article->save());
        $this->assertSame([], $this->db->queries);
    }

    /**
     * ID'd model built manually (no store load): legacy blind-UPDATE
     * parity — one UPDATE writing every set non-PK column.
     */
    public function testModelSaveOnManualIdEntityWritesAllSetColumns(): void
    {
        $a = new Article();
        $a->id    = 42;
        $a->title = 'Manual';

        $this->assertTrue($a->save());

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('UPDATE "article"', $q[0]['sql']);
        $this->assertStringContainsString('SET "title" = ?', $q[0]['sql']);
        $this->assertStringNotContainsString('status_code', $q[0]['sql']);
    }

    /**
     * PK-less model: INSERT + auto-increment PK backfill.
     */
    public function testModelSaveInsertsAndBackfillsGeneratedPk(): void
    {
        $a = new Article();
        $a->title      = 'Auto';
        $a->created_at = '2026-09-03 10:00:00';
        $a->status     = 1;
        $this->db->setMockResults([[['id' => 5]]]);

        $this->assertTrue($a->save());
        $this->assertSame(5, $a->id);
        $this->assertStringContainsString('RETURNING "id"', $this->lastDataSql());
    }

    /**
     * Identity: find() hydrates ON the EM pipeline (heap-tracked) — the
     * facade save() needs no adoption on this path, and the EM find of
     * the same row resolves to the SAME object.
     */
    public function testModelFacadeAdoptsIntoSharedHeap(): void
    {
        $row = $this->articleRow(7, 'Seven');
        $this->db->setMockResults([[$row]]);

        $article = Article::find(7);
        $this->assertNotNull($article);
        $this->assertTrue($this->em->contains($article)); // identity-mapped read

        $article->title = 'Adopted';
        $this->assertTrue($article->save()); // heap-diff flush (no adopt needed)
        $this->assertTrue($this->em->contains($article));
        $this->assertSame($article, $this->em->find(Article::class, ['id' => 7]));
    }

    /**
     * Facade delete: adopt + remove + flush → one DELETE by PK.
     */
    public function testModelDeleteEmitsSinglePkDelete(): void
    {
        $row = $this->articleRow(7, 'Doomed');
        $this->db->setMockResults([[$row]]);

        $article = Article::find(7);
        $this->db->clearQueries();

        $this->assertTrue($article->delete());

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('DELETE FROM "article"', $q[0]['sql']);
        $this->assertStringContainsString('WHERE "id" = ?', $q[0]['sql']);
        $this->assertFalse($this->em->contains($article));
    }

    /**
     * Untracked document (Model facade over mongo metadata): save() →
     * INSERT through the EM, routed to the MONGO store (never the SQL
     * store's article_document table). Arrays pass through raw — BSON
     * owns the encoding (no JSON-encode at the EM layer).
     */
    public function testModelFacadeOverMongoMetadataInsertRoutesThroughEm(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));

        $doc = new \Azera\Tests\Orm\Fixtures\ArticleDocument();
        $doc->_id   = null;
        $doc->title = 'Doc One';
        $doc->tags  = ['a', 'b'];

        $this->assertTrue($doc->save());
        // driver-generated id backfilled RAW (cast suppressed — mongo owns
        // identity; no int-decode coercion on the '1' string).
        $this->assertSame('1', $doc->_id);

        $articles = $fakes->for('articles'); // #[Entity(name)] → collection
        $this->assertCount(1, $articles->docs);
        $this->assertSame(['a', 'b'], $articles->docs[0]['tags']); // RAW array, not JSON
        $this->assertSame('Doc One', $articles->docs[0]['title']);
    }

    /**
     * #[Column(cast: true)] on a mongo document FORCES the json cast over
     * the store's castExclusions: the stored value is a JSON TEXT string
     * and hydration decodes it back to the array (cross-backend parity).
     */
    public function testCastTrueForcesJsonEncodingOnMongo(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));

        $doc = new \Azera\Tests\Orm\Fixtures\CastForcedDocument();
        $doc->title = 'Forced';
        $doc->tags  = ['a', 'b'];

        $this->assertTrue($doc->save());

        $articles = $fakes->for('casted_articles'); // #[Entity(name)] → collection
        $this->assertCount(1, $articles->docs);
        $this->assertSame('["a","b"]', $articles->docs[0]['tags'], 'JSON TEXT, not a BSON array');

        // Hydration decodes the text back onto the array property.
        $found = $this->em->find(\Azera\Tests\Orm\Fixtures\CastForcedDocument::class, ['_id' => '1']);
        $this->assertNotNull($found);
        $this->assertSame(['a', 'b'], $found->tags);
    }

    /**
     * #[Column(cast: false)] on a SQL model SUPPRESSES the json cast: no
     * json_encode on write (raw value bound), no decode on read (raw
     * stored text on the property), snapshot keeps the raw form.
     */
    public function testCastFalseSuppressesJsonOnSql(): void
    {
        $e = new \Azera\Tests\Orm\Fixtures\CastSuppressedArticle();
        $e->id   = 1;
        $e->tags = ['a', 'b'];

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];
        $this->assertStringContainsString('INSERT INTO', $q['sql']);
        $this->assertSame(['a', 'b'], $q['params'][1], 'raw array bound, NOT json-encoded');

        // Hydration: the raw stored text passes through UNdecoded.
        [$entity] = FastHydrator::for(\Azera\Tests\Orm\Fixtures\CastSuppressedArticle::class)
            ->hydrate($this->em->heap(), ['id' => '7', 'tags' => '{stored:raw}']);
        $this->assertSame('{stored:raw}', $entity->tags);

        // Snapshot mirrors the raw form — diff stays like-with-like.
        $node = $this->em->heap()->find($entity);
        $this->assertSame('{stored:raw}', $node->data['tags']);
    }

    /**
     * Tracked, unchanged document: save() → false, no mongo ops.
     */
    public function testDocumentFacadeCleanSaveIsNoOp(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));
        $fakes->for('articles')->docs[] = ['_id' => 'abc123', 'title' => 'Doc One', 'tags' => null];

        $doc = $this->em->find(\Azera\Tests\Orm\Fixtures\ArticleDocument::class, ['_id' => 'abc123']);
        $this->assertNotNull($doc);

        $this->assertFalse($doc->save());
        $this->assertCount(1, $fakes->for('articles')->calls); // only the seed find
    }

    /**
     * Tracked, mutated document: save() → diff UPDATE ($set) through the
     * EM — only changed fields, PK excluded from $set.
     */
    public function testDocumentFacadeUpdateWritesOnlyChangedFields(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));
        $fakes->for('articles')->docs[] = ['_id' => 'abc123', 'title' => 'Doc One', 'tags' => null];

        $doc = $this->em->find(\Azera\Tests\Orm\Fixtures\ArticleDocument::class, ['_id' => 'abc123']);
        $this->assertNotNull($doc);

        $doc->title = 'Doc Two';
        $this->assertTrue($doc->save());

        $articles = $fakes->for('articles');
        $update   = null;
        foreach ($articles->calls as $call) {
            if ($call['op'] === 'update') {
                $update = $call;
            }
        }
        $this->assertNotNull($update);
        $this->assertSame(['title' => 'Doc Two'], $update['args'][1]['$set']);
        $this->assertSame('Doc Two', $articles->docs[0]['title']);
    }

    /**
     * Document BASE-CLASS facade (extends Document, not Model): delete()
     * routes remove + flush through the EM — one mongo deleteOne by _id.
     */
    public function testDocumentBaseFacadeDeleteRoutesThroughEm(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));
        $fakes->for('docs')->docs[] = ['_id' => 'abc123', 'title' => 'Doc One', 'tags' => null];

        $doc = $this->em->find(DocumentFixture::class, ['_id' => 'abc123']);
        $this->assertNotNull($doc);

        $this->assertTrue($doc->delete());

        $this->assertSame([], $fakes->for('docs')->docs); // deleted
        $this->assertFalse($this->em->contains($doc));
    }

    /* ============================================ fresh reads + refresh
     *
     * The identity map is a correctness feature (one row = one object),
     * but heap hits are stale by design. Fresh reads re-read the store
     * and refresh tracked entities IN PLACE — same instance, current
     * values — so polling tasks see the DB without breaking identity. */

    public function testFindWithoutFreshKeepsStaleHeapValues(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        // Row changed in storage; stale find() must NOT re-read (heap hit).
        $this->db->clearQueries();
        $this->db->setMockResults([[$this->articleRow(7, 'Changed')]]);

        $again = $this->em->find(Article::class, ['id' => 7]);

        $this->assertSame($a, $again);
        $this->assertSame('Seven', $a->title);
        $this->assertSame([], $this->db->queries); // no SQL — identity hit
    }

    public function testFindFreshReReadsAndRefreshesInPlace(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);
        $this->assertSame('Seven', $a->title);

        // The row changed in storage since the load.
        $this->db->setMockResults([[$this->articleRow(7, 'Updated')]]);

        $again = $this->em->find(Article::class, ['id' => 7], fresh: true);

        $this->assertSame($a, $again);              // identity preserved
        $this->assertSame('Updated', $a->title);    // value refreshed
        $this->assertFalse($this->em->isDirty($a)); // snapshot synced
    }

    public function testFindFreshStillHitsStoreEveryTime(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $this->em->find(Article::class, ['id' => 7]);
        $this->db->clearQueries();

        $this->db->setMockResults([[$this->articleRow(7, 'Poll')]]);
        $this->em->find(Article::class, ['id' => 7], fresh: true);

        $q = $this->dataQueries();
        $this->assertCount(1, $q); // the poll SELECT actually ran
        $this->assertStringContainsString('SELECT', $q[0]['sql']);
    }

    public function testFindByFreshRefreshesTrackedHits(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        $this->db->setMockResults([[$this->articleRow(7, 'Batch')]]);

        $rows = $this->em->findBy(Article::class, ['id' => 7], fresh: true);

        $this->assertSame($a, $rows[0]);
        $this->assertSame('Batch', $a->title);
    }

    public function testFindByFreshKeepsScheduledEntityState(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        $a->title = 'Pending local edit';
        $this->em->persist($a); // scheduled, not flushed

        $this->db->setMockResults([[$this->articleRow(7, 'Batch')]]);
        $rows = $this->em->findBy(Article::class, ['id' => 7], fresh: true);

        $this->assertSame($a, $rows[0]);
        $this->assertSame('Pending local edit', $a->title); // pending work intact
    }

    public function testRefreshReReadsAndSyncsSnapshot(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        $this->db->setMockResults([[$this->articleRow(7, 'Polled')]]);

        $this->assertSame($a, $this->em->refresh($a));
        $this->assertSame('Polled', $a->title);
        $this->assertFalse($this->em->isDirty($a));
    }

    public function testRefreshReturnsNullAndDetachesWhenRowGone(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Doomed')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        $this->db->setMockResults([[]]); // row deleted in storage

        $this->assertNull($this->em->refresh($a));
        $this->assertFalse($this->em->contains($a));
    }

    public function testRefreshThrowsOnUntrackedEntity(): void
    {
        $this->expectException(\LogicException::class);
        $this->em->refresh(new Article());
    }

    public function testRefreshThrowsOnScheduledEntity(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        $a->title = 'Unflushed';
        $this->em->persist($a);

        $this->expectException(\LogicException::class);
        $this->em->refresh($a);
    }

    public function testFindFreshThrowsOnScheduledEntity(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);

        $a->title = 'Unflushed';
        $this->em->persist($a);

        $this->expectException(\LogicException::class);
        $this->em->find(Article::class, ['id' => 7], fresh: true);
    }

    public function testQueryFreshTerminalRefreshesTrackedEntities(): void
    {
        $row = $this->articleRow(9, 'Shared');
        $this->db->setMockResults([[$row]]);
        $viaEm = $this->em->find(Article::class, ['id' => 9]);
        $this->assertNotNull($viaEm);

        // The row changed in storage; a fresh() criteria read refreshes
        // the tracked instance in place (same object, current values).
        $this->db->clearQueries();
        $this->db->setMockResults([[$this->articleRow(9, 'Fresh')]]);

        $viaQuery = \Azera\Db\Query::modelFor(Article::class, $this->db)
            ->where('id', '=', 9)
            ->fresh()
            ->firstEntity();

        $this->assertSame($viaEm, $viaQuery);
        $this->assertSame('Fresh', $viaEm->title);
    }

    public function testModelFacadeFindFreshAndRefresh(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $article = Article::find(7);
        $this->assertNotNull($article);

        $this->db->setMockResults([[$this->articleRow(7, 'Facade')]]);

        $same = Article::find(7, fresh: true);

        $this->assertSame($article, $same);
        $this->assertSame('Facade', $article->title);

        // Instance refresh() re-reads too.
        $this->db->setMockResults([[$this->articleRow(7, 'Again')]]);
        $this->assertSame($article, $article->refresh());
        $this->assertSame('Again', $article->title);
    }

    /* ================================================== identity guard
     *
     * The PK is the entity's storage identity: diff() excludes PK columns
     * from UPDATE SET and executeUpdate() targets the identity captured
     * at schedule time. A mutated PK on a tracked entity used to be
     * dropped SILENTLY (and kept reporting dirty forever via the
     * dirty-state API) — now scheduleUpdate() throws.
     */

    /**
     * Mutating the PK of a tracked entity must throw at persist() time —
     * loud instead of silently dropped. After the refused attempt the
     * entity stays tracked under its ORIGINAL identity and remains fully
     * usable: a legit non-PK mutation still flushes as a diff UPDATE
     * against the original row.
     */
    public function testPkMutationOnTrackedEntityThrowsAndEntityStaysUsable(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);
        $this->assertNotNull($a);
        $this->db->clearQueries();

        $a->id = 8; // identity mutation — must not go through silently
        try {
            $this->em->persist($a);
            $this->fail('Expected LogicException for PK mutation on a tracked entity');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("PK field 'id'", $e->getMessage());
        }

        // Nothing scheduled, nothing written, identity unchanged.
        $this->assertFalse($this->em->isScheduled($a));
        $this->assertSame([], $this->db->queries);
        $this->assertSame($a, $this->em->find(Article::class, ['id' => 7]));

        // The guard keeps tripping while the mutated value sits on the
        // property — persist() never "heals" a changed identity.
        try {
            $this->em->persist($a);
            $this->fail('Expected the identity guard to keep tripping');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("PK field 'id'", $e->getMessage());
        }

        // Restore the identity property and the entity is fully usable
        // again: a title diff flushes as a diff UPDATE, WHERE pinned to
        // the ORIGINAL id 7.
        $a->id    = 7;
        $a->title = 'Renamed';
        $this->em->persist($a);
        $this->em->flush();

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('UPDATE "article"', $q[0]['sql']);
        $this->assertStringContainsString('SET "title" = ?', $q[0]['sql']);
        $this->assertStringContainsString('WHERE "id" = ?', $q[0]['sql']);
        $this->assertSame('7', $q[0]['params'][1] ?? null);
    }

    /**
     * Untracked entities keep the "everything set counts" semantic —
     * INCLUDING the PK (legitimate pending INSERT data).
     */
    public function testDirtyDataOnUntrackedEntityIncludesPk(): void
    {
        $a = new Article();
        $a->id    = 42;
        $a->title = 'New';

        $this->assertSame(
            ['id' => 42, 'title' => 'New'],
            $this->em->dirtyData($a)
        );
        $this->assertTrue($this->em->isDirty($a));
    }

    /**
     * Tracked entity: the dirty-state API must match what flush() would
     * actually write — PK columns are identity, not data, so mutating the
     * PK alone must NOT report dirty (that's the identity guard's job).
     */
    public function testDirtyDataOnTrackedEntityExcludesPk(): void
    {
        $this->db->setMockResults([[$this->articleRow(7, 'Seven')]]);
        $a = $this->em->find(Article::class, ['id' => 7]);
        $this->assertNotNull($a);

        $a->id = 8; // PK-only mutation: never data
        $this->assertSame([], $this->em->dirtyData($a));
        $this->assertFalse($this->em->isDirty($a));

        // A real data change still reports — field-keyed, PK excluded.
        $a->title = 'Mutated';
        $this->assertSame(['title' => 'Mutated'], $this->em->dirtyData($a));
    }

    /* ============================================ flushAll — multi-connection
     *
     * flushAll() executes the WHOLE scheduled write set across every
     * store/connection it touches: one global topological pass, lazily
     * begun per-target txs, commits deferred until every node executed
     * (failure → all begun txs rolled back — best-effort all-or-nothing). */

    /**
     * Same store instance, two write targets (two Databases): flushAll()
     * writes BOTH — the scenario flush() throws on.
     */
    public function testFlushAllSpansTwoWriteTargetsOnOneStore(): void
    {
        $primary = new TestDatabase('pgsql');
        $this->ctx->dbManager()->set('primary', $primary);

        $audit = new AuditEntry();
        $audit->id    = 1;
        $audit->title = 'audit';
        $this->em->persist($audit);

        $a = new Article();
        $a->id    = 2;
        $a->title = 'article';
        $this->em->persist($a);

        $this->em->flushAll();

        $q = $this->dataQueries();
        $this->assertCount(1, $q);
        $this->assertStringStartsWith('INSERT INTO "article"', $q[0]['sql']);

        $pq = array_values(array_filter($primary->queries, fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)));
        $this->assertCount(1, $pq);
        $this->assertStringStartsWith('INSERT INTO "audit_entry"', $pq[0]['sql']);

        // Both txs committed (deferred commit, two BEGIN/COMMIT pairs).
        $this->assertSame(1, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'BEGIN')));
        $this->assertSame(1, count(array_filter($primary->queries, fn($q) => $q['sql'] === 'BEGIN')));
        $this->assertFalse($this->db->inTransaction());
        $this->assertFalse($primary->inTransaction());
    }

    /**
     * Two write roles aliasing the SAME Database: one tx — exactly one
     * BEGIN, both rows written, one COMMIT.
     */
    public function testFlushAllJoinsRoleAliasesIntoOneTransaction(): void
    {
        $this->ctx->dbManager()->set('primary', $this->db); // alias of 'write'

        $audit = new AuditEntry();
        $audit->id    = 1;
        $audit->title = 'audit';
        $this->em->persist($audit);

        $a = new Article();
        $a->id    = 2;
        $a->title = 'article';
        $this->em->persist($a);

        $this->em->flushAll();

        $this->assertSame(1, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'BEGIN')));
        $this->assertSame(1, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'COMMIT')));
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(2, count($this->dataQueries())); // two INSERTs
    }

    /**
     * Mixed SQL + mongo write set: two store INSTANCES, each committing
     * its own tx (mongo's is a no-op) — flush() throws here, flushAll()
     * routes both.
     */
    public function testFlushAllSpansSqlAndMongoStores(): void
    {
        $fakes = new FakeMongoFactory();
        $this->em->setStore('mongo', new MongoStore(fn($name) => $fakes->for($name)));

        $doc = new \Azera\Tests\Orm\Fixtures\ArticleDocument();
        $doc->_id   = 'abc123';
        $doc->title = 'Doc One';
        $this->em->upsert($doc);

        $a = new Article();
        $a->id    = 2;
        $a->title = 'article';
        $this->em->persist($a);

        $this->em->flushAll();

        $this->assertCount(1, $this->dataQueries()); // the SQL INSERT
        $articles = $fakes->for('articles');
        $this->assertCount(1, $articles->calls);
        $this->assertSame('update', $articles->calls[0]['op']); // the upsert
    }

    /**
     * Failure in a LATER group's execution rolls back the EARLIER begun
     * tx (deferred commits — no partial commit of the first group).
     */
    public function testFlushAllRollsBackEarlierGroupsOnLaterFailure(): void
    {
        // 'primary' rejects the second write.
        $primary = new class extends TestDatabase
        {
            public function query($statement, $params = null): \Azera\Tests\Db\TestPdoStatement
            {
                if (str_contains($statement, 'INSERT')) {
                    throw new \RuntimeException('primary exploded');
                }
                return parent::query($statement, $params);
            }
        };
        $this->ctx->dbManager()->set('primary', $primary);

        $a = new Article();
        $a->id    = 2;
        $a->title = 'article';
        $this->em->persist($a);

        $audit = new AuditEntry();
        $audit->id    = 1;
        $audit->title = 'audit';
        $this->em->persist($audit);

        try {
            $this->em->flushAll();
            $this->fail('Expected the primary connection failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('primary exploded', $e->getMessage());
        }

        // First group's (already-begun) tx rolled back, not committed.
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(1, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'ROLLBACK')));
        $this->assertSame(0, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'COMMIT')));
        // The later group's begun tx is rolled back too.
        $this->assertSame(1, count(array_filter($primary->queries, fn($q) => $q['sql'] === 'ROLLBACK')));
        $this->assertSame(0, count(array_filter($primary->queries, fn($q) => $q['sql'] === 'COMMIT')));
    }

    /**
     * Caller-owned open tx on one target: flushAll() JOINS it (no second
     * BEGIN) and does NOT commit it — the caller unwinds its own tx.
     */
    public function testFlushAllJoinsCallerTxWithoutCommittingIt(): void
    {
        $this->db->begin(); // caller's tx on the default write connection

        $a = new Article();
        $a->id    = 2;
        $a->title = 'article';
        $this->em->persist($a);

        $this->em->flushAll();

        $this->assertSame(1, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'BEGIN')));
        $this->assertSame(0, count(array_filter($this->db->queries, fn($q) => $q['sql'] === 'COMMIT')));
        $this->assertTrue($this->db->inTransaction()); // still the caller's

        $this->db->commit(); // caller unwinds
    }

    /**
     * flush() keeps its single-group atomicity throw for multi-target
     * write sets (the message now points at flushAll()).
     */
    public function testFlushStillThrowsOnMultiTargetWriteSet(): void
    {
        $this->ctx->dbManager()->set('primary', new TestDatabase('pgsql'));

        $audit = new AuditEntry();
        $audit->id    = 1;
        $audit->title = 'audit';
        $this->em->persist($audit);

        $a = new Article();
        $a->id    = 2;
        $a->title = 'article';
        $this->em->persist($a);

        try {
            $this->em->flush();
            $this->fail('Expected flush() to reject a multi-connection write set');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('flush() spans multiple connections', $e->getMessage());
            $this->assertStringContainsString('flushAll()', $e->getMessage());
        }
    }
}