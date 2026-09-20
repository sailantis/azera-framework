<?php

declare(strict_types=1);

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';
require_once __DIR__ . '/Fixtures/EnumColumns.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Orm\Attribute\Column;
use Azera\Orm\Casting\Casts;
use Azera\Orm\Casting\EnumCast;
use Azera\Orm\EntityManager;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Model;
use Azera\Orm\Storage\PdoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use Azera\Tests\Orm\Fixtures\ArticleLevel;
use Azera\Tests\Orm\Fixtures\ArticleStatus;
use Azera\Tests\Orm\Fixtures\EnumArticle;
use Azera\Tests\Orm\Fixtures\PureEnumArticle;
use Azera\Tests\Orm\Fixtures\SuppressedEnumArticle;
use PHPUnit\Framework\TestCase;

/* ------------------------------------------------------------- fixtures */

/**
 * Enum PK — allowed but deliberately undocumented. The heap identity key
 * is built from RAW row values (before any cast), so an enum PK must
 * still produce a stable scalar key.
 */
class EnumPkArticle extends Model
{
    #[Column(pk: true, type: ArticleStatus::class)]
    public ArticleStatus $status_code;

    public string $title;
}

/* ---------------------------------------------------------------- tests */

final class EnumCastTest extends TestCase
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
        Casts::clear();

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

    private function fullRow(array $overrides = []): array
    {
        return array_merge([
            'id'         => '1',
            'status'     => 'draft',
            'level'      => null,
            'explicit'   => 'published',
            'raw_status' => 'draft',
        ], $overrides);
    }

    private function hydrated(array $overrides = []): EnumArticle
    {
        [$entity] = FastHydrator::for(EnumArticle::class)->hydrate(
            $this->em->heap(),
            $this->fullRow($overrides)
        );

        return $entity;
    }

    /** All logged queries except tx control. */
    private function dataQueries(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    /* ------------------------------------------------- metadata typing */

    public function testEnumPropertyInfersItsClassAsColumnType(): void
    {
        $meta = Metadata::for(EnumArticle::class);

        // The enum CLASS is the type key — not 'string'.
        $this->assertSame(ArticleStatus::class, $meta['columns']['status']['type']);
        $this->assertSame(ArticleLevel::class, $meta['columns']['level']['type']);

        // Explicit #[Column(type:)] is the same key, and still applies.
        $this->assertSame(ArticleStatus::class, $meta['columns']['explicit']['type']);

        // `?T` still drives nullability through the normal resolution.
        $this->assertTrue($meta['columns']['level']['nullable']);
        $this->assertFalse($meta['columns']['status']['nullable']);

        // SQL excludes no types, so the cast resolves ACTIVE.
        $this->assertTrue($meta['columns']['status']['cast']);
    }

    public function testCastIsDerivedFromTheEnumClassWithoutRegistration(): void
    {
        $meta = Metadata::for(EnumArticle::class);

        $cast = Casts::forColumn($meta['columns']['status']);

        $this->assertInstanceOf(EnumCast::class, $cast);

        // Derived, NOT registered: types() lists explicit registrations only.
        $this->assertNotContains(ArticleStatus::class, Casts::types());
    }

    public function testDerivedCastIsMemoizedPerEnumClass(): void
    {
        $this->assertSame(
            EnumCast::for(ArticleStatus::class),
            Casts::for(ArticleStatus::class),
            'one shared instance per enum class'
        );
    }

    public function testNonEnumTypesStayCastFree(): void
    {
        // Negative hits are memoized too — 'string' must never gain a cast.
        $this->assertNull(Casts::for('string'));
        $this->assertNull(Casts::for('nonexistent_class_name'));
    }

    public function testRegistrationOverridesADerivedOrNegativeLookup(): void
    {
        // Prime the negative memo, then register — the explicit cast wins.
        $this->assertNull(Casts::for('late_registered'));

        Casts::register('late_registered', new EnumCast(ArticleStatus::class));

        $this->assertInstanceOf(EnumCast::class, Casts::for('late_registered'));
    }

    public function testClearDropsDerivedCasts(): void
    {
        $this->assertInstanceOf(EnumCast::class, Casts::for(ArticleStatus::class));

        Casts::clear();

        // The registry is reset (built-ins re-register on next use) and the
        // derived-lookup memo is dropped, but derivation still works — the
        // per-enum INSTANCES survive, being immutable and stateless.
        $this->assertSame(
            ['int', 'float', 'bool', 'json', 'pgarray', 'datetime'],
            Casts::types()
        );
        $this->assertInstanceOf(EnumCast::class, Casts::for(ArticleStatus::class));
    }

    /* ---------------------------------------------------- enum decoding */

    public function testHydrationPutsCasesOnThePropertyAndScalarsInTheSnapshot(): void
    {
        $e = $this->hydrated(['level' => '2']);

        $this->assertSame(ArticleStatus::Draft, $e->status);
        $this->assertSame(ArticleLevel::Normal, $e->level);
        $this->assertSame(ArticleStatus::Public, $e->explicit);

        // The snapshot holds the SCALAR form so diff() compares like with
        // like — this is what prevents a phantom UPDATE.
        $node = $this->em->heap()->find($e);
        $this->assertSame('draft', $node->data['status']);
        $this->assertSame(2, $node->data['level'], 'int-backed driver string normalised');
        $this->assertSame('published', $node->data['explicit']);
    }

    public function testIntBackedEnumAcceptsDriverStringForm(): void
    {
        // pdo_pgsql / emulated-prepare MySQL return numerics as strings.
        $this->assertSame(ArticleLevel::High, $this->hydrated(['level' => '3'])->level);
    }

    public function testNullableEnumColumnAcceptsNull(): void
    {
        $e = $this->hydrated(['level' => null]);

        $this->assertNull($e->level);
    }

    public function testInvalidBackingValueThrowsInsteadOfDecodingToNull(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->hydrated(['status' => 'not-a-status']);
    }

    public function testUnassignableValueThrowsFromTheCast(): void
    {
        $this->expectException(\RuntimeException::class);

        // A float is never a backed enum's scalar.
        $this->hydrated(['level' => 1.5]);
    }

    /* ---------------------------------------------------- enum encoding */

    public function testPersistBindsTheScalarNotTheCaseObject(): void
    {
        $e = new EnumArticle();
        $e->id       = 1;
        $e->status   = ArticleStatus::Public;
        $e->explicit = ArticleStatus::Draft;

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];

        $this->assertStringContainsString('INSERT INTO', $q['sql']);
        $this->assertContains('published', $q['params'], 'case encoded to its value');
        $this->assertNotContains(ArticleStatus::Public, $q['params'], 'never binds the case object');
    }

    public function testUnchangedHydratedEnumEntityPersistsNothing(): void
    {
        $e = $this->hydrated(['level' => '2']);

        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame([], $this->dataQueries(), 'no phantom UPDATE for enum columns');
    }

    public function testMutatedEnumPropertyEmitsAnUpdate(): void
    {
        $e = $this->hydrated();

        $e->status = ArticleStatus::Closed;

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];

        $this->assertStringContainsString('UPDATE', $q['sql']);
        $this->assertContains('closed', $q['params']);
    }

    public function testEnumValuesRoundTripThroughFind(): void
    {
        $this->db->setMockResults([[$this->fullRow(['status' => 'closed', 'level' => '1'])]]);

        $e = $this->em->find(EnumArticle::class, ['id' => 1]);

        $this->assertSame(ArticleStatus::Closed, $e->status);
        $this->assertSame(ArticleLevel::Low, $e->level);
    }

    /* ---------------------------------------------------------- enums in PKs */

    public function testEnumPkBuildsAStableIdentityKey(): void
    {
        $this->db->setMockResults([
            [
                ['status_code' => 'draft', 'title' => 'T'],
            ]
        ]);

        $e = $this->em->find(EnumPkArticle::class, ['status_code' => 'draft']);

        $this->assertSame(ArticleStatus::Draft, $e->status_code);

        // The heap id is the RAW scalar, so a second read of the same row
        // resolves to the same instance (identity map contract).
        $again = $this->em->find(EnumPkArticle::class, ['status_code' => 'draft']);

        $this->assertSame($e, $again);
    }

    /* -------------------------------------------------- compile-time refusal */

    public function testPureEnumPropertyIsRefusedAtCompileTime(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('pure enum');

        Metadata::for(PureEnumArticle::class);
    }

    public function testEnumTypedPropertyWithSuppressedCastIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cast is SUPPRESSED');

        Metadata::for(SuppressedEnumArticle::class);
    }

    public function testCastSuppressionOnAnUntypedColumnKeepsRawValues(): void
    {
        $meta = Metadata::for(EnumArticle::class);

        $this->assertFalse($meta['columns']['raw']['cast']);
        $this->assertNull(Casts::forColumn($meta['columns']['raw']));

        // Raw pass-through both directions — the caller owns the shape.
        $e = $this->hydrated(['raw_status' => 'draft']);

        $this->assertSame('draft', $e->raw);
    }
}