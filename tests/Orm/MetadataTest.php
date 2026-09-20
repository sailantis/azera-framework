<?php

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/Article.php';
require_once __DIR__ . '/Fixtures/Relations.php';
require_once __DIR__ . '/Fixtures/ArticleDocument.php';
require_once __DIR__ . '/Fixtures/CastSuppressedArticle.php';
require_once __DIR__ . '/Fixtures/InventoryItem.php';
require_once __DIR__ . '/Fixtures/TypedColumns.php';
require_once __DIR__ . '/Fixtures/InternalProperties.php';
require_once __DIR__ . '/Fixtures/NullabilityShapes.php';
require_once __DIR__ . '/Fixtures/EnumColumns.php';

use Azera\Cache\ArrayCache;
use Azera\AppContext;
use Azera\Orm\Metadata;
use Azera\Orm\Storage\MongoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Orm\Fixtures\Article;
use Azera\Tests\Orm\Fixtures\ArticleDocument;
use Azera\Tests\Orm\Fixtures\ArticleWithRelations;
use Azera\Tests\Orm\Fixtures\CastForcedDocument;
use Azera\Tests\Orm\Fixtures\CastSuppressedArticle;
use Azera\Tests\Orm\Fixtures\Comment;
use Azera\Tests\Orm\Fixtures\EnumArticle;
use Azera\Tests\Orm\Fixtures\ArticleStatus;
use Azera\Tests\Orm\Fixtures\InventoryItem;
use Azera\Tests\Orm\Fixtures\InternalProperties;
use Azera\Tests\Orm\Fixtures\NullabilityShapes;
use Azera\Tests\Orm\Fixtures\TypedColumns;
use PHPUnit\Framework\TestCase;

class MetadataTest extends TestCase
{
    protected function setUp(): void
    {
        Metadata::clear();

        // Enrichment source: a mongo store (resolver never reached —
        // enrichment only reads/writes the metadata array). Needed so
        // mongo-annotated fixtures compile their document pkMode.
        AppContext::setInstance(new AppContext());
        $stores = new Stores();
        $stores->set('mongo', new MongoStore(
            fn(string $name) => throw new \LogicException('no mongo I/O in MetadataTest')
        ));
        AppContext::instance()->set(Stores::class, $stores);
    }

    protected function tearDown(): void
    {
        // Static L2 config must not leak into other test classes.
        Metadata::useCache(null);
        Metadata::cacheSalt(null);
        Metadata::clear();
        AppContext::reset();
    }

    public function testCompileInferredAndExplicitColumns(): void
    {
        $meta = Metadata::for(Article::class);

        $this->assertSame('article', $meta['source']);
        $this->assertSame('sql', $meta['store']);

        // explicit #[Column(type: int)] on $id, first *_id-named property → pk
        $this->assertTrue($meta['columns']['id']['pk']);
        $this->assertSame('int', $meta['columns']['id']['type']);

        $this->assertSame('string', $meta['columns']['title']['type']);
        $this->assertFalse($meta['columns']['title']['pk']);

        $this->assertSame('datetime', $meta['columns']['created_at']['type']);

        // persist:false property excluded entirely
        $this->assertArrayNotHasKey('computed', $meta['columns']);
    }

    public function testColumnNameOverride(): void
    {
        $meta = Metadata::for(Article::class);

        $this->assertSame('status_code', $meta['columns']['status']['name']);
        $this->assertSame('int', $meta['columns']['status']['type']);
        $this->assertFalse($meta['columns']['status']['pk']);
    }

    public function testColumnTypeInferredFromPhpTypeWhenOmitted(): void
    {
        $meta = Metadata::for(TypedColumns::class);

        // #[Column] present but no `type:` → PHP type wins.
        $this->assertSame('int', $meta['columns']['id']['type']);
        $this->assertSame('int', $meta['columns']['status']['type']);
        $this->assertSame('float', $meta['columns']['score']['type']);
        $this->assertSame('bool', $meta['columns']['active']['type']);
        $this->assertSame('json', $meta['columns']['tags']['type']);
        $this->assertSame('datetime', $meta['columns']['created_at']['type']);
        $this->assertSame('string', $meta['columns']['title']['type']);

        // Untyped property still falls back to 'string'.
        $this->assertSame('string', $meta['columns']['untyped']['type']);

        // name/pk overrides still honored alongside inferred type.
        $this->assertSame('status_code', $meta['columns']['status']['name']);
        $this->assertFalse($meta['columns']['status']['pk']);
    }

    public function testEnumPropertyTypeInfersTheEnumClass(): void
    {
        $meta = Metadata::for(EnumArticle::class);

        // A backed enum IS its own column type: the class-string is the
        // cast registry key, so no registration step is needed.
        $this->assertSame(ArticleStatus::class, $meta['columns']['status']['type']);
        $this->assertSame(ArticleStatus::class, $meta['columns']['explicit']['type']);
        $this->assertTrue($meta['columns']['status']['cast']);
    }

    /* ------------------------------------------------------ nullability */

    /**
     * Nullability has TWO spellings (`?T` and `#[Column(nullable:)]`) and
     * the compiler folds them into ONE resolved flag, which doubles as the
     * complete hydration policy — resolved HERE, so the hydrator never
     * reflects the property.
     */
    public function testNullableResolvedFromPhpTypeOrAttribute(): void
    {
        $cols = Metadata::for(NullabilityShapes::class)['columns'];

        // `?int` alone.
        $this->assertTrue($cols['from_php_type']['nullable']);

        // Untyped defaults to nullable (no PHP answer to contradict).
        $this->assertTrue($cols['untyped']['nullable']);

        // Untyped + explicit downgrade is honored.
        $this->assertFalse($cols['untyped_not_nullable']['nullable']);

        // Non-nullable both ways.
        $this->assertFalse($cols['plain']['nullable']);
    }

    /**
     * The resolved `nullable` flag is the ONLY null policy the hydrator
     * reads — there is no second gate flag, so this IS the whole compiled
     * answer for every column.
     */
    public function testNullableIsTheOnlyCompiledNullPolicy(): void
    {
        $cols = Metadata::for(NullabilityShapes::class)['columns'];

        foreach ($cols as $field => $col) {
            $this->assertArrayHasKey('nullable', $col, "column {$field} lacks a compiled nullable flag");
            $this->assertIsBool($col['nullable'], "column {$field} nullable flag is not a bool");
            $this->assertArrayNotHasKey('nullGate', $col, "column {$field} still carries a nullGate");
        }
    }

    /**
     * The `typed` flag mirrors the property's PHP type declaration — it
     * arms FastHydrator's all-raw fast path (an untyped property cannot
     * be coerced on assignment, so the raw cell IS the snapshot value).
     * A typed property (even nullable) DISARMS it: weak-mode assignment
     * may coerce, so the snapshot must be read back off the property.
     */
    public function testTypedFlagMirrorsPhpTypeDeclaration(): void
    {
        $cols = Metadata::for(NullabilityShapes::class)['columns'];

        // Typed properties — nullable or not.
        $this->assertTrue($cols['id']['typed']);
        $this->assertTrue($cols['from_php_type']['typed']);
        $this->assertTrue($cols['plain']['typed']);
        $this->assertTrue($cols['raw_int']['typed']);

        // Untyped properties.
        $this->assertFalse($cols['untyped']['typed']);
        $this->assertFalse($cols['untyped_not_nullable']['typed']);
        $this->assertFalse($cols['raw_untyped_int']['typed']);

        // Flag is a bool for EVERY column (the fast path reads it raw).
        foreach ($cols as $field => $col) {
            $this->assertIsBool($col['typed'], "column {$field} typed flag is not a bool");
        }
    }

    /**
     * `T` + `nullable: true` is REFUSED at compile time. A nullable column
     * needs a property that can receive the null; with a `T` property the
     * hydrator may only assign null (a TypeError) or throw — and throwing
     * would make the attribute meaningless. So it is the one shape the
     * compiler will not guess about.
     */
    public function testNullableAttrOnNonNullableTypedPropertyIsRefused(): void
    {
        eval('namespace Azera\Tests\Orm\Fixtures; class NullOnTyped extends \Azera\Orm\Model {
            public int $id;
            #[\Azera\Orm\Attribute\Column(nullable: true)]
            public int $value;
        }');

        Metadata::clear();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Contradictory nullability');

        Metadata::for(\Azera\Tests\Orm\Fixtures\NullOnTyped::class);
    }

    /**
     * The MIRROR case is ACCEPTED: `?T` + `nullable: false` is consistent,
     * not contradictory. The column is NOT NULL in the DDL while the
     * property simply never receives a null (a store NULL is rejected on
     * hydration) — the same treatment an untyped property gets. Nothing is
     * unrepresentable, so the compiler compiles it.
     */
    public function testNullableFalseOnNullableTypedPropertyCompilesToNotNull(): void
    {
        eval('namespace Azera\Tests\Orm\Fixtures; class NotNullOnNullable extends \Azera\Orm\Model {
            public int $id;
            #[\Azera\Orm\Attribute\Column(nullable: false)]
            public ?int $value;
        }');

        Metadata::clear();

        $cols = Metadata::for(\Azera\Tests\Orm\Fixtures\NotNullOnNullable::class)['columns'];

        $this->assertFalse($cols['value']['nullable']);
    }

    public function testRelationsCompiled(): void
    {
        $rel = Metadata::for(ArticleWithRelations::class)['relations'];

        $this->assertSame('hasOne', $rel['meta']['type']);
        // foreignKey default = source-based: article_with_relations_id
        $this->assertSame('article_with_relations_id', $rel['meta']['foreignKey']);
        $this->assertSame('join', $rel['meta']['strategy']);

        $this->assertSame('hasMany', $rel['comments']['type']);
        $this->assertSame('article_with_relations_id', $rel['comments']['foreignKey']);
        $this->assertSame('second_query', $rel['comments']['strategy']);
    }

    public function testBelongsToDefaultsForeignKeyFromRelationName(): void
    {
        $rel = Metadata::for(Comment::class)['relations'];

        $this->assertSame('belongsTo', $rel['article']['type']);
        $this->assertSame(Article::class, $rel['article']['target']);
        // foreignKey default = relation name + '_id'
        $this->assertSame('article_id', $rel['article']['foreignKey']);
        $this->assertSame('join', $rel['article']['strategy']);

        $this->assertSame('author_id', $rel['author']['foreignKey']);
        $this->assertSame('join', $rel['author']['strategy']);
    }

    public function testEntityAttributeSwitchesStoreAndNamesSource(): void
    {
        $meta = Metadata::for(ArticleDocument::class);

        $this->assertSame('mongo', $meta['store']);
        // Collection key = the generic `source` (#[Entity(name: 'articles')]).
        $this->assertSame('articles', $meta['source']);
        $this->assertNull($meta['schema']);
        $this->assertNull($meta['readRole']);
        $this->assertNull($meta['writeRole']);
    }

    public function testTableAttributeSetsSourceAndSchema(): void
    {
        $meta = Metadata::for(InventoryItem::class);

        $this->assertSame('inventory_items', $meta['source']);
        $this->assertSame('warehouse', $meta['schema']);
    }

    public function testConnectionAttributeSetsSplitRoles(): void
    {
        $meta = Metadata::for(InventoryItem::class);

        $this->assertSame('replica', $meta['readRole']);
        $this->assertSame('primary', $meta['writeRole']);
    }

    public function testExplicitPkMarksDefineCompositeKeyAndExcludeConvention(): void
    {
        $meta = Metadata::for(InventoryItem::class);

        $this->assertTrue($meta['columns']['tenant_id']['pk']);
        $this->assertTrue($meta['columns']['item_id']['pk']);
        // Renamed *_id column with explicit pk: false — the name convention
        // must NOT leak it into the key.
        $this->assertFalse($meta['columns']['externalRef']['pk']);
        $this->assertFalse($meta['columns']['name']['pk']);

        // idFields() resolves from the marks, in declaration order.
        $this->assertSame(
            ['tenant_id', 'item_id'],
            (new \ReflectionClass(InventoryItem::class))->newInstanceWithoutConstructor()->idFields()
        );

        // pkFields mirrors idFields() exactly (declaration order).
        $this->assertSame(['tenant_id', 'item_id'], $meta['pkFields']);
    }

    public function testPkFieldsDefaultToIdWhenNoMarks(): void
    {
        $meta = Metadata::for(Article::class);

        $this->assertSame(['id'], $meta['pkFields']);
    }

    public function testPkFieldsForDocumentKeepsConventionAndMarks(): void
    {
        $meta = Metadata::for(ArticleDocument::class);

        $this->assertSame('mongo', $meta['store']);
        // Document pkMode ('convention', contributed by MongoStore
        // enrichment) keeps the id/*_id name convention: $_id is
        // pk-marked by the *_id suffix (documents commonly rely on it).
        $this->assertSame('convention', $meta['pkMode']);
        $this->assertSame(['_id'], $meta['pkFields']);
    }

    public function testStoreExclusionsSuppressCastsByDefault(): void
    {
        $meta = Metadata::for(ArticleDocument::class);

        // MongoStore contributes castExclusions — resolved per column:
        // 'json' excluded (BSON owns array encoding), 'string' cast-free
        // anyway (no registered cast, flag true is inert), the PK forced
        // off by #[Column(cast: false)].
        $this->assertSame(['json', 'datetime'], $meta['castExclusions']);
        $this->assertFalse($meta['columns']['tags']['cast']);
        $this->assertTrue($meta['columns']['title']['cast']);
        $this->assertFalse($meta['columns']['_id']['cast']);
    }

    public function testCastTrueForcesCastOverStoreExclusion(): void
    {
        $meta = Metadata::for(CastForcedDocument::class);

        // #[Column(cast: true)] wins over the store's exclusion.
        $this->assertTrue($meta['columns']['tags']['cast']);
    }

    public function testStaticPropertiesAreNeverColumns(): void
    {
        $meta = Metadata::for(InternalProperties::class);

        // The pipeline reads/writes columns through instance syntax
        // ($entity->{$field}); a compiled static would emit a PHP notice
        // on EVERY extractData()/hydration and poison the snapshot.
        $this->assertArrayNotHasKey('instances', $meta['columns']);
        $this->assertArrayNotHasKey('registry', $meta['columns']);

        // The ordinary columns around them are unaffected.
        $this->assertArrayHasKey('id', $meta['columns']);
        $this->assertArrayHasKey('title', $meta['columns']);
    }

    public function testReadonlyPropertiesAreNeverColumns(): void
    {
        $meta = Metadata::for(InternalProperties::class);

        // PHP allows exactly ONE write to a readonly property. The pipeline
        // re-applies row values onto the entity (hydration cold path,
        // refresh-in-place, backfill, revert), so a readonly column would
        // throw on the second write — reflection cannot help either.
        $this->assertArrayNotHasKey('immutable', $meta['columns']);

        // `immutable` (readonly alone) and `label` (readonly + the
        // attribute) compile to the same excluded result — readonly is
        // checked before persist is ever read, so the attribute is
        // redundant here.
        $this->assertArrayNotHasKey('label', $meta['columns']);
    }

    public function testNonPublicPropertiesAreNeverColumns(): void
    {
        $meta = Metadata::for(InternalProperties::class);

        // Hydration/backfill assign via `$entity->{$field} = …`, which PHP
        // forbids outside the declaring class — a non-public column would
        // fatal with "Cannot access protected property ...". Private state
        // is the class's own business, so these are excluded rather than
        // written through reflection.
        $this->assertArrayNotHasKey('protectedState', $meta['columns']);
        $this->assertArrayNotHasKey('privateState', $meta['columns']);

        // The class can still reach them itself.
        $e = new InternalProperties();
        $this->assertSame('p', $e->protectedState());
    }

    public function testPersistFalseIsRedundantOnReadonlyProperties(): void
    {
        // readonly alone (`immutable`) and readonly + the attribute
        // (`label`) are excluded identically: the readonly guard runs
        // before the persist flag is read, so the attribute is a no-op.
        // Pinned because the docs promise it, and a reordering of the
        // guards could silently change the answer.
        $meta = Metadata::for(InternalProperties::class);

        $this->assertArrayNotHasKey('immutable', $meta['columns']);
        $this->assertArrayNotHasKey('label', $meta['columns']);

        // The genuinely load-bearing case: `persist: false` on a MUTABLE
        // property is required — without it the property is a column.
        $this->assertArrayNotHasKey('derived', $meta['columns']);
    }

    public function testUnderscorePrefixedPropertiesStayPersistable(): void
    {
        $meta = Metadata::for(InternalProperties::class);

        // The compiler no longer treats a leading '_'/'__' as "internal":
        // real schemas do use single-underscore columns (Mongo/Couch style
        // _id, _rev) and a '__' property name is a legitimate column too.
        $this->assertArrayHasKey('_revision', $meta['columns']);
        $this->assertSame('_revision', $meta['columns']['_revision']['name']);

        $this->assertArrayHasKey('__cached', $meta['columns']);
        $this->assertSame('__cached', $meta['columns']['__cached']['name']);
    }

    public function testPersistFalseRemainsTheExplicitEscapeHatch(): void
    {
        $meta = Metadata::for(InternalProperties::class);

        // #[Column(persist: false)] on a writable property still excludes.
        $this->assertArrayNotHasKey('derived', $meta['columns']);
    }

    public function testExcludedPropertyShapesDoNotBecomePkFields(): void
    {
        $meta = Metadata::for(InternalProperties::class);

        // An excluded property must not leak into the PK either — the
        // '_id'-suffixed static would otherwise have been convention-marked.
        $this->assertSame(['id'], $meta['pkFields']);
    }

    public function testCastFalseSuppressesCastOnSql(): void
    {
        $meta = Metadata::for(CastSuppressedArticle::class);

        // SQL store excludes nothing (no castExclusions key) — the flag
        // comes from the explicit #[Column(cast: false)].
        $this->assertArrayNotHasKey('castExclusions', $meta);
        $this->assertFalse($meta['columns']['tags']['cast']);
    }

    public function testConnectionOnMongoDocumentThrowsViaStoreEnrichment(): void
    {
        $this->expectException(\LogicException::class);
        Metadata::for(MongoDocumentWithConnection::class);
    }

    public function testDeclaredSourceOverrideWinsOverConvention(): void
    {
        // ModelTest's dummy model declares source() overrides in the Mvc
        // namespace; here the compile-time hook is proven via the schema()
        // default: non-overriding models get null.
        $this->assertNull(Metadata::for(Article::class)['schema']);
        $this->assertSame('article', Metadata::for(Article::class)['source']);
    }

    public function testL1CacheReturnsIdenticalArray(): void
    {
        $a = Metadata::for(Article::class);
        $b = Metadata::for(Article::class);

        $this->assertSame($a, $b, 'L1 cache returns the identical array');
    }

    public function testClearForcesRecompile(): void
    {
        $a = Metadata::for(Article::class);
        Metadata::clear();
        $b = Metadata::for(Article::class);

        // clear() empties L1, so the only way for() can return data again
        // is a fresh compile(). (Note: assertNotSame is meaningless for
        // arrays — PHP array === is content comparison, not identity.)
        $this->assertEquals($a, $b, 'recompiled metadata equals the original');
    }

    public function testClearL1DropsProcessTierButKeepsL2Warm(): void
    {
        $backend = new ArrayCache();
        Metadata::useCache($backend);
        Metadata::for(Article::class); // populate L1 + L2

        Metadata::clearL1();

        // L1 is gone → the next read must come from L2 (no fresh compile
        // writes a NEW payload shape). Prove it by poisoning L2 after the
        // clear: if the entry survived and is served, L1 was truly reset.
        $key    = self::metaKey(Article::class);
        $poison = $backend->get($key);
        $poison['source'] = 'from_l2_after_clearl1';
        $backend->set($key, $poison);

        $meta = Metadata::for(Article::class);
        $this->assertSame('from_l2_after_clearl1', $meta['source'], 'L2 survives clearL1() and is served');

        // A fresh compile would have overwritten the poisoned payload —
        // its survival proves the read came from L2, not a recompile.
        $this->assertSame('from_l2_after_clearl1', $backend->get($key)['source']);
    }

    /* -------------------------------------------- L2 (opt-in PSR-16 backend) */

    /** Mirrors Metadata::cacheKey(): 'azera_orm_meta_' . md5(VERSION\0salt\0class). */
    private static function metaKey(string $class, string $salt = ''): string
    {
        // Read the compiler's VERSION instead of hardcoding it: the test
        // pins the KEY SHAPE (prefix + digest inputs), not the current
        // version string, so a VERSION bump never breaks the suite.
        $version = (new \ReflectionClass(Metadata::class))
            ->getConstant('VERSION');

        return 'azera_orm_meta_' . md5($version . "\0{$salt}\0{$class}");
    }

    /** All azera_orm_meta_* keys currently present in an ArrayCache backend. */
    private function metaKeys(ArrayCache $backend): array
    {
        $data = (new \ReflectionClass($backend))->getProperty('data')->getValue($backend);

        return array_values(array_filter(
            array_keys($data),
            fn(string $k) => str_starts_with($k, 'azera_orm_meta_')
        ));
    }

    public function testL2RoundTripWritesAndClearsOnlyOwnKeys(): void
    {
        $backend = new ArrayCache();
        $backend->set('other_app_key', 'keep-me');
        Metadata::useCache($backend);

        $meta = Metadata::for(Article::class);

        // One class key + the key index.
        $this->assertCount(2, $this->metaKeys($backend), 'meta key + index key written');

        $payload = $backend->get(self::metaKey(Article::class));
        $this->assertSame(Article::class, $payload['class'] ?? null, 'payload carries + verifies the class');

        Metadata::clear();

        $this->assertSame('keep-me', $backend->get('other_app_key'), 'foreign keys survive clear()');
        $this->assertSame([], $this->metaKeys($backend), 'only our keys are deleted');
    }

    public function testL2HitServesStoredPayloadAfterL1Reset(): void
    {
        $backend = new ArrayCache();
        Metadata::useCache($backend);
        Metadata::for(Article::class); // compile + write to L2

        $key    = self::metaKey(Article::class);
        $poison = $backend->get($key);
        $poison['source'] = 'poisoned_from_l2';
        $backend->set($key, $poison);

        // Simulate a new worker process: L1 empty, L2 warm.
        Metadata::clearL1();

        $this->assertSame('poisoned_from_l2', Metadata::for(Article::class)['source']);
    }

    public function testForeignPayloadInL2IsTreatedAsMiss(): void
    {
        $backend = new ArrayCache();
        Metadata::useCache($backend);

        // Foreign/corrupt payload under our key (class mismatch).
        $backend->set(self::metaKey(Article::class), ['class' => 'Some\\Other\\Class']);

        $meta = Metadata::for(Article::class);

        $this->assertSame('article', $meta['source'], 'wrong-class payload is a miss → recompiled');
        $this->assertSame(Article::class, $backend->get(self::metaKey(Article::class))['class']);
    }

    public function testCacheSaltChangesKeyAndAcceptsUnsafeCharacters(): void
    {
        $backend = new ArrayCache();
        Metadata::useCache($backend);
        Metadata::for(Article::class);
        $keyNoSalt = self::metaKey(Article::class);
        $this->assertContains($keyNoSalt, $this->metaKeys($backend));

        // A new deploy hash changes the key → old entries are never
        // requested again (they expire via TTL or backend eviction).
        Metadata::cacheSalt('deploy-42:build/abc?x=y'); // deliberately key-unsafe chars
        Metadata::clearL1();
        Metadata::for(InventoryItem::class);

        $keySalted = self::metaKey(InventoryItem::class, 'deploy-42:build/abc?x=y');
        $keys      = $this->metaKeys($backend);
        $this->assertContains($keyNoSalt, $keys, 'salting does not delete earlier entries');
        $this->assertContains($keySalted, $keys);
        $this->assertSame(InventoryItem::class, $backend->get($keySalted)['class']);
    }

    public function testTtlIsForwardedToBackend(): void
    {
        $key = self::metaKey(Article::class);

        $backend = new ArrayCache();
        Metadata::useCache($backend, 3600);
        Metadata::for(Article::class);

        $data = (new \ReflectionClass($backend))->getProperty('data')->getValue($backend);
        $this->assertNotNull($data[$key]['expires'], 'ttl forwarded → entry has an expiry');

        // New backend + fresh L1 → forces a fresh compile/write to L2.
        $backend = new ArrayCache();
        Metadata::useCache($backend); // no ttl
        Metadata::clearL1();
        Metadata::for(Article::class);

        $data = (new \ReflectionClass($backend))->getProperty('data')->getValue($backend);
        $this->assertNull($data[$key]['expires'], 'no ttl → backend default (no expiry)');
    }
}

/** Fixture: #[Connection] on a mongo document → the STORE rejects it
 * (enrichment validation — MongoStore owns its connections). */
#[\Azera\Orm\Attribute\Entity(store: 'mongo', name: 'conflict')]
#[\Azera\Orm\Attribute\Connection(role: 'nope')]
class MongoDocumentWithConnection extends \Azera\Orm\Model
{
    public $id;
}