<?php

declare(strict_types=1);

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/FakeMongoCollection.php';
require_once __DIR__ . '/Fixtures/ArticleDocument.php';

use Azera\Orm\Metadata;
use Azera\Orm\Storage\MongoStore;
use Azera\Tests\Orm\Fixtures\ArticleDocument;
use PHPUnit\Framework\TestCase;

/**
 * MongoStore against the in-memory fake — CRUD round-trip, _id backfill,
 * collection-name resolution, Store seam contract, transaction no-ops.
 * (The live-server E2E round-trip lives in MongoLiveTest, opt-in.)
 */
final class MongoStoreTest extends TestCase
{
    private FakeMongoFactory $fakes;
    private MongoStore $store;

    protected function setUp(): void
    {
        Metadata::clear();
        $this->fakes = new FakeMongoFactory();
        $this->store = new MongoStore(fn(string $name) => $this->fakes->for($name));
    }

    public function testInsertGeneratesIdWhenPkOmitted(): void
    {
        $result = $this->store->insertOne(ArticleDocument::class, [
            'title' => 'One',
            'tags'  => ['a'],
        ]);

        $this->assertSame('1', $result['id']);
        $this->assertNull($result['row']);

        $docs = $this->fakes->for('articles')->docs;
        $this->assertCount(1, $docs);
        $this->assertSame('1', $docs[0]['_id']);
    }

    public function testInsertStoresRawArrayForExcludedCastType(): void
    {
        // Store-level: EM delivers RAW values for castExclusions types —
        // the EM's metadata 'cast' => false on tags (mongo exclusion) is
        // what keeps the array un-JSON-encoded upstream.
        $this->store->insertOne(ArticleDocument::class, [
            'title' => 'Raw',
            'tags'  => ['x', 'y'],
        ]);

        $this->assertSame(['x', 'y'], $this->fakes->for('articles')->docs[0]['tags']);
    }

    public function testInsertKeepsCallerProvidedId(): void
    {
        $result = $this->store->insertOne(ArticleDocument::class, [
            '_id'   => 'custom-42',
            'title' => 'Two',
        ]);

        $this->assertSame('custom-42', $result['id']);
        $this->assertSame('custom-42', $this->fakes->for('articles')->docs[0]['_id']);
    }

    public function testCollectionNameFromAttribute(): void
    {
        $this->store->insertOne(ArticleDocument::class, ['title' => 'X']);
        // #[Entity(name: 'articles')] — NOT the snake/plural fallback
        $this->assertTrue($this->fakes->collections['articles'] !== null);
    }

    public function testCollectionNameFallsBackToConvention(): void
    {
        // Collection key = the generic `source` metadata (#[Entity(name)]).
        $meta = Metadata::for(ArticleDocument::class);
        $this->assertSame('articles', $meta['source']);
    }

    public function testFindByPkAndFindByRouteThroughFind(): void
    {
        $fakes = $this->fakes->for('articles');
        $fakes->docs = [
            ['_id' => 'a', 'title' => 'A', 'tags' => null],
            ['_id' => 'b', 'title' => 'B', 'tags' => null],
        ];

        $row = $this->store->findByPk(ArticleDocument::class, ['_id' => 'b']);
        $this->assertSame('B', $row['title']);

        $rows = $this->store->findBy(ArticleDocument::class, ['title' => 'A']);
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['_id']);

        $this->assertNull($this->store->findByPk(ArticleDocument::class, ['_id' => 'zz']));
    }

    public function testUpdateUsesSetAndPkFilter(): void
    {
        $fakes = $this->fakes->for('articles');
        $fakes->docs = [['_id' => 'a', 'title' => 'Old', 'tags' => ['x']]];

        $result = $this->store->updateOne(
            ArticleDocument::class,
            ['title' => 'New'],
            ['_id' => 'a'],
        );

        $this->assertSame(['row' => null, 'id' => null], $result);
        $this->assertSame('New', $fakes->docs[0]['title']);
        $this->assertSame(['x'], $fakes->docs[0]['tags']); // untouched ($set partial)

        $update = $fakes->calls[0] ?? null; // docs seeded directly: update is the first op
        $this->assertNotNull($update);
        $this->assertSame('update', $update['op']);
        $this->assertSame(['$set' => ['title' => 'New']], $update['args'][1]);
    }

    public function testDeleteRemovesMatchingDoc(): void
    {
        $fakes = $this->fakes->for('articles');
        $fakes->docs = [['_id' => 'a', 'title' => 'A']];

        $this->store->deleteOne(ArticleDocument::class, ['_id' => 'a']);
        $this->assertSame([], $fakes->docs);
    }

    /**
     * upsertOne = updateOne(filter: PK, $set minus PK, upsert:true) — the
     * mongo twin of ON CONFLICT. Hit path: $set merges onto the doc.
     */
    public function testUpsertUpdatesExistingDoc(): void
    {
        $fakes = $this->fakes->for('articles');
        $fakes->docs = [['_id' => 'a', 'title' => 'Old', 'tags' => ['x']]];

        $result = $this->store->upsertOne(ArticleDocument::class, [
            '_id'   => 'a',
            'title' => 'New',
        ]);

        $this->assertSame(['row' => null, 'id' => null], $result); // hit: no new id
        $this->assertSame('New', $fakes->docs[0]['title']);
        $this->assertSame(['x'], $fakes->docs[0]['tags']); // untouched ($set partial)

        $update = $fakes->calls[0];
        $this->assertSame('update', $update['op']);
        $this->assertSame(['_id' => 'a'], $update['args'][0]);
        $this->assertSame(['$set' => ['title' => 'New']], $update['args'][1]);
        $this->assertTrue($update['args'][2]['upsert']);
    }

    /**
     * Upsert miss: the server mints the id — the fake mirrors the driver
     * semantic (upsertedId backfill), the doc lands with filter+$set merged.
     */
    public function testUpsertInsertsWhenMissingAndBackfillsGeneratedId(): void
    {
        $fakes = $this->fakes->for('articles');

        $result = $this->store->upsertOne(ArticleDocument::class, [
            '_id'   => 'custom-42',
            'title' => 'Fresh',
        ]);

        $this->assertSame('custom-42', $result['id']); // upserted doc keeps the filter's id
        $this->assertCount(1, $fakes->docs);
        $this->assertSame('custom-42', $fakes->docs[0]['_id']);
        $this->assertSame('Fresh', $fakes->docs[0]['title']);
        $this->assertSame(['title' => 'Fresh'], $fakes->calls[0]['args'][1]['$set']);
    }

    public function testCount(): void
    {
        $fakes = $this->fakes->for('articles');
        $fakes->docs = [
            ['_id' => 'a', 'title' => 'A'],
            ['_id' => 'b', 'title' => 'A'],
            ['_id' => 'c', 'title' => 'B'],
        ];

        $this->assertSame(3, $this->store->count(ArticleDocument::class));
        $this->assertSame(2, $this->store->count(ArticleDocument::class, ['title' => 'A']));
    }

    public function testTransactionsAreStructuralNoOps(): void
    {
        $this->store->begin();
        $this->assertFalse($this->store->inTransaction());
        $this->store->commit();
        $this->store->rollback();

        $calls = $this->fakes->for('articles')->calls;
        $this->assertSame([], $calls); // nothing sent to the server
    }

    public function testCollectionsAreCachedPerClass(): void
    {
        $store = new MongoStore(fn(string $name) => $this->fakes->for($name));

        $store->findByPk(ArticleDocument::class, ['_id' => 'x']);
        $store->findByPk(ArticleDocument::class, ['_id' => 'y']);

        $p = new \ReflectionProperty(MongoStore::class, 'collections');
        $this->assertCount(1, $p->getValue($store)); // resolved once
    }
}