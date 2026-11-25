<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/ArticleDocument.php';
require_once __DIR__ . '/FakeMongoCollection.php';
require_once __DIR__ . '/../Db/TestDatabase.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Db\Query;
use Azera\Orm\Attribute\Column;
use Azera\Orm\Attribute\Entity;
use Azera\Orm\Document;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\MongoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use Azera\Tests\Orm\Fixtures\ArticleDocument;
use PHPUnit\Framework\TestCase;

/** Document facade fixture: extends Document (NOT Model). */
#[Entity(store: 'mongo', name: 'doc_only')]
class DocOnlyFacadeDocument extends Document
{
    #[Column(cast: false)]
    public $_id;
    public $title;
}

/** Plain class + attributes: no facade at all, EM only. */
#[Entity(store: 'mongo', name: 'plain_docs')]
class PlainMongoClass
{
    #[Column(cast: false)]
    public $_id;
    public $title;
}

/** SQL control fixture. */
class SqlControlModel extends \Azera\Orm\Model
{
    public $id;
    public $title;
}

/** Document fixture with an explicit two-column key. */
#[Entity(store: 'mongo', name: 'composite_docs')]
class CompositeDocument extends Document
{
    #[Column(pk: true)]
    public $tenant_id;
    #[Column(pk: true)]
    public $slug;
}

/**
 * Document with NO key-ish property: metadata falls back to pkFields
 * ['id'] even though no column is marked pk. delete() must refuse instead
 * of addressing a phantom identifier.
 */
#[Entity(store: 'mongo', name: 'no_key_docs')]
class NoKeyDocument extends Document
{
    public $title;
}

/**
 * Store routing: the SQL-only query builder must refuse a class that is
 * routed to another store, and the facade finders must work on every
 * backend because they go through the EntityManager's Store seam.
 */
final class StoreRoutingTest extends TestCase
{
    private AppContext $ctx;
    private TestDatabase $db;
    private FakeMongoFactory $fakes;

    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
        $this->ctx = AppContext::instance();
        FastHydrator::clear();
        Metadata::clear();
        $this->db = new TestDatabase('pgsql');
        $dbm      = new DatabaseManager();
        $dbm->set('default', $this->db);
        $dbm->set('read', $this->db);
        $dbm->set('write', $this->db);
        $this->ctx->set(DatabaseManager::class, $dbm);

        $stores = new Stores();
        $this->ctx->set(Stores::class, $stores);

        $this->fakes = new FakeMongoFactory();
        $this->ctx->entityManager()->setStore(
            'mongo',
            new MongoStore(fn(string $name) => $this->fakes->for($name), database: 'test')
        );

        $this->fakes->for('articles')->docs    = [['_id' => 'a1', 'title' => 'From Mongo', 'tags' => []]];
        $this->fakes->for('doc_only')->docs    = [['_id' => 'd1', 'title' => 'Doc Facade']];
        $this->fakes->for('plain_docs')->docs  = [['_id' => 'p1', 'title' => 'Plain Class']];

        // A SQL answer that must never be produced for a document class.
        $this->db->setMockResults(array_fill(0, 6, [[
            ['_id' => 'WRONG', 'title' => 'SQL-ANSWER', 'tags' => '[]'],
        ]]));
    }

    protected function tearDown(): void
    {
        AppContext::reset();
    }

    /* ------------------------------------------------ the guard (#1) */

    public function testQueryRefusesNonSqlStore(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("routed to the 'mongo' store");

        ArticleDocument::query();
    }

    public function testWithRefusesNonSqlStore(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("routed to the 'mongo' store");

        ArticleDocument::with('author');
    }

    public function testCountAndExistsRefuseNonSqlStore(): void
    {
        foreach (['count', 'exists'] as $method) {
            try {
                \call_user_func([ArticleDocument::class, $method], []);
                $this->fail("{$method}() must refuse a non-SQL store");
            } catch (\LogicException $e) {
                $this->assertStringContainsString("'mongo' store", $e->getMessage());
                // The error must point at the working alternative.
                $this->assertStringContainsString('EntityManager', $e->getMessage());
            }
        }
    }

    public function testGuardDoesNotExecuteSql(): void
    {
        try {
            ArticleDocument::findAll();
        } catch (\Throwable) {
            // not expected, asserted below
        }

        try {
            ArticleDocument::query();
        } catch (\LogicException) {
            // expected
        }

        foreach (['count', 'exists'] as $method) {
            try {
                \call_user_func([ArticleDocument::class, $method], []);
            } catch (\LogicException) {
                // expected
            }
        }

        try {
            ArticleDocument::with('author');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame([], $this->db->queries, 'no statement may reach the SQL connection');
    }

    public function testRawLiteralQueriesAreUnaffected(): void
    {
        $this->db->setMockResults([[['id' => 1]]]);

        Query::raw($this->db)->table('some_table')->select();

        $this->assertStringContainsString('SELECT', (string) $this->db->getLastSql());
    }

    public function testSqlModelStillUsesBuilder(): void
    {
        $this->db->setMockResults([[['id' => 1, 'title' => 'X']]]);

        $rows = SqlControlModel::query()->entities();

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('SELECT', (string) $this->db->getLastSql());
    }

    /* ------------------------------------ facade finders on documents (#2) */

    public function testDocumentFacadeExposesEmBackedFinders(): void
    {
        foreach (['find', 'findOrFail', 'findOne', 'findAll'] as $method) {
            $this->assertTrue(
                method_exists(DocOnlyFacadeDocument::class, $method),
                "Document must expose {$method}()"
            );
        }
    }

    public function testDocumentFacadeDoesNotExposeBuilderReaders(): void
    {
        // Builder readers compile SQL — they must not exist on Document.
        foreach (['query', 'with', 'fresh', 'exists', 'count'] as $method) {
            $this->assertFalse(
                method_exists(DocOnlyFacadeDocument::class, $method),
                "Document must not expose {$method}() — it compiles SQL"
            );
        }
    }

    public function testDocumentFindReadsTheMongoStore(): void
    {
        $doc = DocOnlyFacadeDocument::find('d1');

        $this->assertInstanceOf(DocOnlyFacadeDocument::class, $doc);
        $this->assertSame('Doc Facade', $doc->title);
        $this->assertSame([], $this->db->queries, 'find() must not touch SQL');
    }

    public function testDocumentFindOrFailThrowsWhenMissing(): void
    {
        $this->assertNull(DocOnlyFacadeDocument::find('nope'));

        $this->expectException(\RuntimeException::class);
        DocOnlyFacadeDocument::findOrFail('nope');
    }

    public function testDocumentFindAllAndFindOneUseTheMongoStore(): void
    {
        $all = DocOnlyFacadeDocument::findAll();
        $one = DocOnlyFacadeDocument::findOne(['title' => 'Doc Facade']);

        $this->assertCount(1, $all);
        $this->assertSame('Doc Facade', $all[0]->title);
        $this->assertSame($all[0], $one, 'identity map: same document, same instance');
        $this->assertSame([], $this->db->queries);
    }

    public function testModelSubclassOnMongoUsesEmFindersOnly(): void
    {
        $this->assertSame('From Mongo', ArticleDocument::find('a1')?->title);
        $this->assertSame('From Mongo', ArticleDocument::findOne([])?->title);
        $this->assertCount(1, ArticleDocument::findAll());
        $this->assertSame([], $this->db->queries, 'no SQL for a mongo-backed model');
    }

    public function testFindIsIdentityMappedAcrossFacadeAndEm(): void
    {
        $viaFacade = DocOnlyFacadeDocument::find('d1');
        $viaEm     = $this->ctx->entityManager()->find(DocOnlyFacadeDocument::class, ['_id' => 'd1']);

        $this->assertSame($viaFacade, $viaEm);
    }

    /* ------------------------------------------- plain class, EM only (#3) */

    public function testPlainAttributeClassWorksThroughTheEm(): void
    {
        $found = $this->ctx->entityManager()->find(PlainMongoClass::class, ['_id' => 'p1']);

        $this->assertInstanceOf(PlainMongoClass::class, $found);
        $this->assertSame('Plain Class', $found->title);
        $this->assertSame([], $this->db->queries);
    }

    public function testPlainAttributeClassHasNoFacadeSurface(): void
    {
        foreach (['find', 'findOne', 'save', 'delete'] as $method) {
            $this->assertFalse(
                method_exists(PlainMongoClass::class, $method),
                "a plain class must not gain {$method}() — use the EntityManager"
            );
        }
    }

    /* ---------------------------------------------------- id normalizing */

    public function testIdArrayCountMismatchIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ID array count mismatch');

        // DocOnlyFacadeDocument keys on a single field (_id), so a two-value
        // array cannot be mapped onto it.
        DocOnlyFacadeDocument::find(['a', 'b']);
    }

    public function testScalarIdRejectsCompositeKeyClasses(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Composite key classes require an array of ID values');

        CompositeDocument::find('scalar');
    }

    /* ------------------------------- shared writers (trait) on documents */

    public function testDocumentFacadeExposesSharedWriters(): void
    {
        foreach (['create', 'upsert', 'firstOrCreate', 'updateOrCreate'] as $method) {
            $this->assertTrue(
                method_exists(DocOnlyFacadeDocument::class, $method),
                "Document must expose {$method}() — it is backend-agnostic"
            );
        }
    }

    public function testCreateInsertsIntoTheMongoStore(): void
    {
        $doc = DocOnlyFacadeDocument::create(['title' => 'Created']);

        $docs = $this->fakes->for('doc_only')->docs;

        $this->assertInstanceOf(DocOnlyFacadeDocument::class, $doc);
        $this->assertNotNull($doc->_id, 'the driver-generated _id is backfilled');
        $this->assertCount(2, $docs); // one seeded + one created
        $this->assertSame('Created', $docs[1]['title']);
        $this->assertSame([], $this->db->queries, 'create() must not touch SQL');
    }

    public function testUpsertWritesThroughTheMongoStore(): void
    {
        $doc = DocOnlyFacadeDocument::upsert(['_id' => 'u1', 'title' => 'Upserted']);

        $docs = $this->fakes->for('doc_only')->docs;
        $titles = array_column($docs, 'title');

        $this->assertInstanceOf(DocOnlyFacadeDocument::class, $doc);
        $this->assertContains('Upserted', $titles);
        $this->assertSame([], $this->db->queries, 'upsert() must not touch SQL');
    }

    public function testFirstOrCreateReturnsExistingMatch(): void
    {
        // 'd1' is seeded with title 'Doc Facade'.
        $found = DocOnlyFacadeDocument::firstOrCreate(['title' => 'Doc Facade'], ['_id' => 'ignored']);

        $this->assertSame('Doc Facade', $found->title);
        $this->assertCount(1, $this->fakes->for('doc_only')->docs, 'no second document created');
    }

    public function testFirstOrCreateCreatesWhenMissing(): void
    {
        $created = DocOnlyFacadeDocument::firstOrCreate(
            ['title' => 'Brand New'],
            ['_id' => 'created-1']
        );

        $docs = $this->fakes->for('doc_only')->docs;

        $this->assertSame('Brand New', $created->title);
        $this->assertCount(2, $docs);
        $this->assertSame([], $this->db->queries);
    }

    public function testUpdateOrCreateUpdatesTheMatch(): void
    {
        $updated = DocOnlyFacadeDocument::updateOrCreate(
            ['title' => 'Doc Facade'],
            ['title' => 'Renamed']
        );

        $this->assertSame('Renamed', $updated->title);
        $this->assertSame('Renamed', $this->fakes->for('doc_only')->docs[0]['title']);
        $this->assertSame([], $this->db->queries);
    }

    public function testWritersAlsoAvailableOnModelSubclassOverMongo(): void
    {
        $created = ArticleDocument::create(['title' => 'Via Model facade']);

        $this->assertNotNull($created->_id);
        $this->assertSame('Via Model facade', $created->title);
        $this->assertSame([], $this->db->queries, 'no SQL for a mongo-backed Model');
    }

    public function testCreateOnSqlModelStillUsesTheSqlStore(): void
    {
        $this->db->setMockResults([[['id' => 5, 'title' => 'Inserted']]]);

        $model = SqlControlModel::create(['title' => 'Inserted']);

        $this->assertSame('Inserted', $model->title);
        $sqls = implode("\n", array_column($this->db->queries, 'sql'));
        $this->assertStringContainsString('INSERT INTO', $sqls);
    }

    /* ---------------------------------------- delete() key guard on documents */

    /**
     * A composite key with one column null has no storage identity. The
     * EntityManager would treat the untracked instance as "never persisted"
     * and detach it, so remove()+flush() silently deleted nothing while
     * delete() returned true. Every key field must be checked, not just the
     * first one.
     */
    public function testDeleteRequiresEveryCompositeKeyField(): void
    {
        $this->fakes->for('composite_docs')->docs[] = [
            'tenant_id' => 't1',
            'slug'      => 's1',
            'title'     => 'Present',
        ];

        $doc = new CompositeDocument();
        $doc->tenant_id = 't1';
        // slug deliberately left null.

        try {
            $doc->delete();
            $this->fail('delete() must refuse a partial key');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'slug' not set", $e->getMessage());
        }

        $this->assertCount(
            1,
            $this->fakes->for('composite_docs')->docs,
            'the stored document must survive a refused delete()'
        );
    }

    /**
     * An untracked document with a full key still holds a real identity, so
     * delete() must adopt it first — remove() on an untracked instance only
     * detaches and deletes nothing.
     */
    public function testDeleteAdoptsAnUntrackedDocument(): void
    {
        $this->fakes->for('doc_only')->docs[] = ['_id' => 'untracked', 'title' => 'Ghost'];

        $doc = new DocOnlyFacadeDocument();
        $doc->_id   = 'untracked';
        $doc->title = 'Ghost';

        $this->assertFalse(
            $this->ctx->entityManager()->contains($doc),
            'precondition: the instance is not in the identity map'
        );

        $this->assertTrue($doc->delete());

        $ids = array_column($this->fakes->for('doc_only')->docs, '_id');
        $this->assertNotContains('untracked', $ids, 'the stored document must actually be deleted');
    }

    public function testDeleteRejectsADocumentWithNoKeyAtAll(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not set');

        (new NoKeyDocument())->delete();
    }
}
