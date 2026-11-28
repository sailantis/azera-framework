<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/Fixtures/Relations.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Orm\EntityManager;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\PdoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use Azera\Tests\Orm\Fixtures\Article;
use Azera\Tests\Orm\Fixtures\ArticleWithRelations;
use Azera\Tests\Orm\Fixtures\Author;
use PHPUnit\Framework\TestCase;

/**
 * A facade `save()` must write ONE entity, not the whole heap.
 *
 * The bug this pins: `save()` called `EntityManager::flush()`, which
 * executes every scheduled node in the request. So saving entity B also
 * flushed entity A's pending INSERT — and if A's INSERT was doomed, B's
 * save threw with A's error instead of writing B.
 *
 * That is exactly how a production Stripe webhook failed: provisioning
 * scheduled a `billing.customers` INSERT (duplicate `stripe_customer_id`),
 * then `licenseKeyForCheckout()` saved the checkout session to record the
 * derived license key. Because that save flushed the whole heap, it
 * retried the doomed customer INSERT and threw the same unique violation.
 * The error handler that was meant to RECORD the failure then hit it a
 * third time, so the 500 came with no recorded cause at all.
 *
 * These tests assert on the SQL actually issued, which is what
 * distinguishes "wrote only what was asked" from "wrote the whole heap".
 */
class EntitySaveScopeTest extends TestCase
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

    /** @return list<array{sql: string, params: mixed}> */
    private function dataQueries(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    /**
     * @return list<string>
     */
    private function insertedTables(): array
    {
        $tables = [];

        foreach ($this->dataQueries() as $query) {
            if (preg_match('/^\s*INSERT\s+INTO\s+"?([A-Za-z_][A-Za-z0-9_]*)"?/i', $query['sql'], $m) === 1) {
                $tables[] = strtolower($m[1]);
            }
        }

        return $tables;
    }

    /**
     * The core regression: two unrelated pending inserts, one save.
     */
    public function testSavingOneEntityDoesNotFlushAnUnrelatedScheduledEntity(): void
    {
        $article = new Article();
        $article->title = 'checkout-session-like';
        $this->em->persist($article);           // scheduled INSERT, NOT flushed

        $author = new Author();
        $author->name = 'customer-like';

        $author->save();                        // must write ONLY the author

        $tables = $this->insertedTables();

        $this->assertContains(
            'author',
            $tables,
            'the entity being saved must be written',
        );
        $this->assertNotContains(
            'article',
            $tables,
            'saving an author must not flush an unrelated queued article INSERT: '
            . 'that coupling is what let a doomed write abort an unrelated save',
        );
    }

    /**
     * The unrelated entity must stay queued, not be silently dropped: the
     * caller still holds it and a later flush has to write it.
     */
    public function testTheDeferredEntityRemainsScheduledForALaterFlush(): void
    {
        $article = new Article();
        $article->title = 'still-pending';
        $this->em->persist($article);

        $author = new Author();
        $author->name = 'written-first';
        $author->save();

        $this->assertTrue(
            $this->em->isScheduled($article),
            'the deferred entity must remain scheduled rather than be discarded',
        );

        $this->em->flush();

        $this->assertContains('article', $this->insertedTables());
    }

    /**
     * The failing shape from production, reproduced deterministically:
     * saving the "session" row must produce a clean INSERT of that row and
     * must NOT re-issue the earlier "customer" INSERT.
     */
    public function testSavingTheSessionDoesNotReissueTheEarlierCustomerInsert(): void
    {
        $customer = new Author();               // stands in for billing.customers
        $customer->name = 'cus_Vi1M5MDMx33UH6';
        $this->em->persist($customer);

        $session = new Article();               // stands in for checkout_sessions
        $session->title = 'cs_test_purchase';

        $session->save();

        // Count INSERTs per table: exactly one, and for the session only.
        $counts = array_count_values($this->insertedTables());

        $this->assertSame(1, $counts['article'] ?? 0, 'the session row is written once');
        $this->assertSame(
            0,
            $counts['author'] ?? 0,
            'the pending customer INSERT must not be replayed by an unrelated save',
        );
    }

    /**
     * Narrowing must not break the reason a whole-heap flush sometimes
     * matters: a `belongsTo` owner whose generated key feeds the dependent
     * has to be written in the same unit of work.
     */
    public function testABelongsToOwnerIsStillFlushedWithItsDependent(): void
    {
        $author = new Author();
        $author->name = 'owner-not-yet-written';
        $this->em->persist($author);

        $article = new ArticleWithRelations();
        $article->title = 'dependent';

        $article->save();

        $tables = $this->insertedTables();

        $this->assertContains('article_with_relations', $tables, 'the dependent is written');
        $this->assertContains(
            'author',
            $tables,
            'a scheduled belongsTo OWNER must still be flushed with its dependent, '
            . 'otherwise the FK has no parent row to point at',
        );
    }
}
