<?php

declare(strict_types=1);

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/ArticleDocument.php';

use Azera\AppContext;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\MongoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Orm\Fixtures\ArticleDocument;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * LIVE end-to-end: MongoStore ↔ real MongoDB server (localhost:27017).
 *
 * @group live
 */
final class MongoLiveTest extends TestCase
{
    private AppContext $ctx;

    protected function setUp(): void
    {
        // Package-presence guard BEFORE any MongoDB\Client autoload: this is
        // the only test that needs the real mongodb/mongodb package (all
        // other Mongo tests use the in-memory fake via the resolver seam).
        // The package lives in `suggest`, not `require-dev`, so machines
        // without it must skip here instead of erroring on the autoload.
        if (!class_exists(\MongoDB\Client::class)) {
            $this->markTestSkipped(
                'mongodb/mongodb package not installed (composer require mongodb/mongodb + ext-mongodb)'
            );
        }

        try {
            $client = new Client('mongodb://localhost:27017');
            $client->azera_live_test->command(['ping' => 1]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MongoDB server not reachable at localhost:27017');
        }

        Metadata::clear();
        FastHydrator::clear();
        $this->ctx = new AppContext();
        AppContext::setInstance($this->ctx);

        $stores = new Stores();
        $stores->set('mongo', new MongoStore($client, 'azera_live_test'));
        $this->ctx->set(Stores::class, $stores);
        $this->ctx->entityManager(); // EM registered request-scoped
    }

    protected function tearDown(): void
    {
        AppContext::reset();
        // Same package guard as setUp — if the live test never ran (skip),
        // there is nothing to drop; also avoids a pointless autoload error
        // on machines without the package.
        if (!class_exists(\MongoDB\Client::class)) {
            return;
        }
        try {
            (new Client('mongodb://localhost:27017'))->dropDatabase('azera_live_test');
        } catch (\Throwable) {}
    }

    /**
     * Full pipeline: Document facade save() → INSERT → ObjectId backfill →
     * find() identity → diff UPDATE → delete() — all against the real server.
     */
    public function testDocumentRoundTripOnLiveServer(): void
    {
        $doc = new ArticleDocument();
        $doc->title = 'Live One';
        $doc->tags  = ['x', 'y'];

        $this->assertTrue($doc->save());
        $this->assertNotNull($doc->_id); // ObjectId string backfilled

        $found = ArticleDocument::find($doc->_id);
        $this->assertNotNull($found);
        $this->assertSame('Live One', $found->title);
        $this->assertSame(['x', 'y'], $found->tags); // BSON array round-trip

        $found->title = 'Live Two';
        $this->assertTrue($found->save()); // diff UPDATE ($set)

        $again = ArticleDocument::find($found->_id);
        $this->assertSame('Live Two', $again->title);

        $this->assertTrue($found->delete());
        $this->assertNull(ArticleDocument::find($found->_id));
    }
}