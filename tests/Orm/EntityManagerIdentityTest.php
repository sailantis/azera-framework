<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/FakeMongoCollection.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Orm\Attribute\Column;
use Azera\Orm\Attribute\Entity;
use Azera\Orm\Document;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\MongoStore;
use Azera\Orm\Storage\PdoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use PHPUnit\Framework\TestCase;

/** No key metadata at all: metadata falls back to pkFields ['id']. */
class NoKeyModel extends \Azera\Orm\Model
{
    public $title;
}

/** Composite key via idFields(). */
class CompositeKeyModel extends \Azera\Orm\Model
{
    public $tenant_id;
    public $slug;
    public $title;

    public function idFields(): array
    {
        return ['tenant_id', 'slug'];
    }
}

/** Mongo document with a composite key via explicit marks. */
#[Entity(store: 'mongo', name: 'composite_docs')]
class CompositeKeyDocument extends Document
{
    #[Column(pk: true)]
    public $tenant_id;

    #[Column(pk: true)]
    public $slug;

    public $title;
}

/**
 * adopt() asserts "the row already exists", so it must refuse an identity
 * it cannot address. Before this guard, a null key field was accepted and
 * produced a MANAGED node whose id array contained nulls: `WHERE 1=0`
 * no-ops on SQL, a wrong-row match on Mongo (equality filter), and a heap
 * collision for two key-less instances.
 */
final class EntityManagerIdentityTest extends TestCase
{
    private AppContext $ctx;
    private TestDatabase $db;
    private \Azera\Orm\EntityManager $em;
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
        $stores->set('sql', new PdoStore($dbm, 'read', 'write'));
        $this->ctx->set(Stores::class, $stores);

        $this->fakes = new FakeMongoFactory();
        $this->ctx->entityManager()->setStore(
            'mongo',
            new MongoStore(fn(string $name) => $this->fakes->for($name), database: 'test')
        );

        $this->em = $this->ctx->entityManager();
    }

    protected function tearDown(): void
    {
        AppContext::reset();
    }

    /** Data statements only — TestDatabase logs BEGIN/COMMIT/ROLLBACK too. */
    private function dataQueries(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    /* ---------------------------------------------------- adopt() guard */

    public function testAdoptRejectsAMissingKey(): void
    {
        $entity = new \Azera\Tests\Orm\Fixtures\Article();
        $entity->title = 'No id';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("missing 'id'");

        $this->em->adopt($entity);
    }

    public function testAdoptRejectsAPartialCompositeKey(): void
    {
        $entity = new CompositeKeyModel();
        $entity->tenant_id = 't1';   // slug deliberately unset
        $entity->title     = 'partial';

        try {
            $this->em->adopt($entity);
            $this->fail('adopt() must refuse a partial composite key');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("missing 'slug'", $e->getMessage());
            $this->assertStringContainsString('tenant_id, slug', $e->getMessage(), 'the message names the full key');
            $this->assertStringContainsString('persist()', $e->getMessage(), 'the message points at the working call');
        }
    }

    public function testAdoptRejectsAClassWithoutAnyKeyMetadata(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no primary key is defined');

        $this->em->adopt(new NoKeyModel());
    }

    public function testAdoptAcceptsACompleteIdentity(): void
    {
        $entity = new \Azera\Tests\Orm\Fixtures\Article();
        $entity->id    = 7;
        $entity->title = 'Tracked';

        $this->assertSame($entity, $this->em->adopt($entity));
        $this->assertTrue($this->em->contains($entity));
        $this->assertSame(['id' => 7], $this->em->heap()->find($entity)->id);
    }

    public function testTwoKeyLessInstancesCannotCollapseOntoOneNode(): void
    {
        $a = new NoKeyModel();
        $a->title = 'A';
        $b = new NoKeyModel();
        $b->title = 'B';

        // Previously both adopted with id=[] and shared ONE heap node, so the
        // second instance was unreachable and its writes hit the first's node.
        foreach ([$a, $b] as $entity) {
            try {
                $this->em->adopt($entity);
                $this->fail('adopt() must refuse a key-less instance');
            } catch (\LogicException) {
                // expected for both
            }
        }

        $this->assertNull($this->em->heap()->find($a));
        $this->assertNull($this->em->heap()->find($b));
        $this->assertNull($this->em->heap()->findById(NoKeyModel::class, ['id' => null]));
    }

    public function testAdoptGuardEmitsNoSql(): void
    {
        try {
            $this->em->adopt(new \Azera\Tests\Orm\Fixtures\Article());
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame([], $this->db->queries);
    }

    /* ------------------------------------------- remove() adopts untracked */

    public function testRemoveAdoptsAnUntrackedEntityWithACompleteIdentity(): void
    {
        $entity = new \Azera\Tests\Orm\Fixtures\Article();
        $entity->id    = 42;
        $entity->title = 'Hand-built';

        $this->assertFalse($this->em->contains($entity));

        $this->em->remove($entity)->flush();

        $queries = $this->dataQueries();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('DELETE FROM "article"', $queries[0]['sql']);
        $this->assertStringContainsString('WHERE "id" = ?', $queries[0]['sql']);
        $this->assertSame(42, $queries[0]['params'][0]);
        $this->assertFalse($this->em->contains($entity));
    }

    public function testRemoveRejectsAnUntrackedEntityWithoutAKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identity is incomplete');

        $this->em->remove(new \Azera\Tests\Orm\Fixtures\Article());
    }

    public function testRemoveCancelsAPendingInsert(): void
    {
        $entity = new \Azera\Tests\Orm\Fixtures\Article();
        $entity->title = 'Transient';

        $this->em->persist($entity);
        $this->em->remove($entity);
        $this->em->flush();

        $this->assertSame([], $this->dataQueries(), 'neither the INSERT nor a DELETE may run');
        $this->assertFalse($this->em->contains($entity));
    }

    public function testRemoveOnATrackedEntityStillDeletes(): void
    {
        $this->db->setMockResults([[['id' => 5, 'title' => 'Loaded', 'created_at' => null, 'status_code' => null]]]);
        $entity = $this->em->find(\Azera\Tests\Orm\Fixtures\Article::class, ['id' => 5]);
        $this->assertNotNull($entity);
        $this->db->clearQueries();

        $this->em->remove($entity)->flush();

        $this->assertCount(1, $this->dataQueries());
        $this->assertNull($this->em->heap()->findById(\Azera\Tests\Orm\Fixtures\Article::class, ['id' => 5]));
    }

    /* ----------------------------------- the wrong-row case this prevents */

    /**
     * On Mongo a null in the filter matches a MISSING field as well as an
     * explicit null, so a partial composite key can delete a document the
     * caller never addressed. The guard rejects the call instead.
     */
    public function testPartialCompositeKeyCannotDeleteTheWrongMongoDocument(): void
    {
        $this->fakes->for('composite_docs')->docs = [
            ['tenant_id' => 't1', 'slug' => 's1', 'title' => 'REAL 1'],
            ['tenant_id' => 't1', 'slug' => null, 'title' => 'REAL 2'],
        ];

        $doc = new CompositeKeyDocument();
        $doc->tenant_id = 't1';   // slug unset
        $doc->title     = 'partial';

        try {
            $this->em->remove($doc);
            $this->fail('a partial key must not reach the store');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("missing 'slug'", $e->getMessage());
        }

        $titles = array_column($this->fakes->for('composite_docs')->docs, 'title');
        $this->assertSame(['REAL 1', 'REAL 2'], $titles, 'no document may be deleted');
    }

    public function testCompleteCompositeKeyDoesDeleteTheRightMongoDocument(): void
    {
        $this->fakes->for('composite_docs')->docs = [
            ['tenant_id' => 't1', 'slug' => 's1', 'title' => 'REAL 1'],
            ['tenant_id' => 't1', 'slug' => 's2', 'title' => 'REAL 2'],
        ];

        $doc = $this->em->find(CompositeKeyDocument::class, ['tenant_id' => 't1', 'slug' => 's1']);
        $this->assertNotNull($doc);

        $this->em->remove($doc)->flush();

        $titles = array_column($this->fakes->for('composite_docs')->docs, 'title');
        $this->assertSame(['REAL 2'], $titles);
    }

    /* ------------------------------- persist() keeps the insert asymmetry */

    /**
     * The guard belongs to adopt() alone: persist() must still tolerate a
     * missing key, because that is exactly what an INSERT looks like (the
     * heap's synthetic-slot path keeps such entities apart).
     */
    public function testPersistStillAcceptsAnEntityWithNoKey(): void
    {
        $entity = new \Azera\Tests\Orm\Fixtures\Article();
        $entity->title = 'New row';

        $this->db->setMockResults([[['id' => 33, 'title' => 'New row', 'created_at' => null, 'status_code' => null]]]);

        $this->em->persist($entity)->flush();

        $queries = $this->dataQueries();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('INSERT INTO "article"', $queries[0]['sql']);
        $this->assertSame(33, $entity->id, 'the generated key is backfilled');
    }

    public function testTwoKeyLessInsertsAreBothScheduled(): void
    {
        $queries = [];
        foreach (['A', 'B'] as $title) {
            $entity = new \Azera\Tests\Orm\Fixtures\Article();
            $entity->title = $title;
            $this->em->persist($entity);
            $queries[] = $entity;
        }

        $this->assertCount(2, $this->em->heap()->scheduled(), 'two pending inserts must not collapse into one');
    }

    /* --------------------- phantom-['id'] fallout: keyless classes at large */

    /**
     * Metadata used to inject `pkFields = ['id']` for a class with no key,
     * even though `id` was not a column. Every consumer then trusted it, and
     * the failures were silent: nodes keyed on `id=null` collapsed, and
     * PdoStore's strategy threw only AFTER the INSERT had run.
     */
    public function testMetadataNeverReportsAKeyFieldThatIsNotAColumn(): void
    {
        $meta = Metadata::for(NoKeyModel::class);

        $this->assertSame([], $meta['pkFields'], 'a class with no key has no key fields');
        $this->assertSame([], array_diff($meta['pkFields'], array_keys($meta['columns'])));
    }

    public function testKeyLessClassCanStillBeInserted(): void
    {
        $this->db->setMockResults([[]]);

        $entity = new NoKeyModel();
        $entity->title = 'No key';

        $this->em->persist($entity)->flush();

        $queries = $this->dataQueries();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('INSERT INTO "no_key_model"', $queries[0]['sql']);
        $this->assertStringNotContainsString('RETURNING', $queries[0]['sql']);
    }

    public function testTwoKeyLessInsertsBothReachTheStore(): void
    {
        $this->db->setMockResults([[], []]);

        $a = new NoKeyModel();
        $a->title = 'A';
        $b = new NoKeyModel();
        $b->title = 'B';

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $inserts = array_filter($this->dataQueries(), fn($q) => str_starts_with($q['sql'], 'INSERT INTO'));
        $this->assertCount(2, $inserts, 'the second key-less INSERT must not be lost');
    }

    public function testUpsertOnAKeyLessClassEmitsAPlainInsert(): void
    {
        $this->db->setMockResults([[]]);

        $entity = new NoKeyModel();
        $entity->title = 'Via upsert';

        $this->em->upsert($entity)->flush();

        $queries = $this->dataQueries();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('INSERT INTO', $queries[0]['sql']);
        $this->assertStringNotContainsString('ON CONFLICT ()', $queries[0]['sql'], 'an empty conflict target is invalid SQL');
    }
}
