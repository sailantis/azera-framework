<?php

declare(strict_types=1);

namespace Azera\Tests\Orm;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';
require_once __DIR__ . '/Fixtures/NullabilityShapes.php';

use Azera\AppContext;
use Azera\Db\DatabaseManager;
use Azera\Orm\Attribute\Column;
use Azera\Orm\Casting\Casts;
use Azera\Orm\Casting\DateTimeCast;
use Azera\Orm\EntityManager;
use Azera\Orm\FastHydrator;
use Azera\Orm\Metadata;
use Azera\Orm\Model;
use Azera\Orm\Storage\PdoStore;
use Azera\Orm\Storage\Stores;
use Azera\Tests\Db\TestDatabase;
use Azera\Tests\Orm\Fixtures\NullabilityShapes;
use PHPUnit\Framework\TestCase;

/* ------------------------------------------------------------- fixtures */

class CastedArticle extends Model
{
    #[Column(type: 'int')]
    public $id;

    public $title;

    /** Portable json default (inferred from `array` too). */
    #[Column(type: 'json')]
    public $tags;

    #[Column(name: 'price_cents', type: 'int')]
    public $priceCents;

    #[Column(type: 'float')]
    public $rating;

    #[Column(type: 'bool')]
    public $published;

    /** pg native array column — cast declared, not inferred. */
    #[Column(name: 'labels', type: 'pgarray')]
    public $labels;

    /** Custom-cast target (registered per-test). */
    #[Column(type: 'upper')]
    public $slug;

    /** Builtin datetime cast (registered since the extractData() merge). */
    #[Column(type: 'datetime')]
    public $published_at;
}

class PlainArticle extends Model
{
    public $id;

    public $title;
}

class PlainGuarded extends Model
{
    public $id;

    public $title;

    #[Column(nullable: false)]
    public $untyped_not_nullable;
}

/* ---------------------------------------------------------------- tests */

final class CastTest extends TestCase
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
        Casts::clear(); // registry is process-static: drop custom registrations

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

    private function hydratedCasted(array $row): CastedArticle
    {
        [$entity] = FastHydrator::for(CastedArticle::class)->hydrate(
            $this->em->heap(),
            $row
        );

        return $entity;
    }

    private function fullRow(array $overrides = []): array
    {
        return array_merge([
            'id'           => '7',
            'title'        => 'T',
            'tags'         => null,
            'price_cents'  => null,
            'rating'       => null,
            'published'    => null,
            'labels'       => null,
            'slug'         => null,
            'published_at' => null,
        ], $overrides);
    }

    /** All logged queries except tx control. */
    private function dataQueries(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    /* ------------------------------------------------- scalar decodes */

    public function testHydrateCoercesNumericStringsInPropertyAndSnapshot(): void
    {
        // Stringifying-driver row: numerics as strings.
        $e = $this->hydratedCasted($this->fullRow([
            'price_cents' => '1200',
            'rating'      => '4.5',
            'published'   => '1',
        ]));

        $this->assertSame(7, $e->id);
        $this->assertSame(1200, $e->priceCents);
        $this->assertSame(4.5, $e->rating);
        $this->assertTrue($e->published);

        // The node snapshot must hold the COERCED values too, otherwise
        // diff() compares int(1200) vs '1200' and flags a phantom change.
        $node = $this->em->heap()->find($e);
        $this->assertSame(1200, $node->data['price_cents']);
        $this->assertSame(4.5, $node->data['rating']);
        $this->assertTrue($node->data['published']);
    }

    public function testHydratedUnchangedEntityPersistsNothing(): void
    {
        $e = $this->hydratedCasted($this->fullRow([
            'price_cents' => '1200',
            'rating'      => '4.5',
            'published'   => '1',
        ]));

        $this->em->persist($e);
        $this->em->flush();

        $data = array_values(array_filter(
            $this->db->queries,
            fn($q) => !in_array($q['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));

        $this->assertSame([], $data, 'unchanged hydrated entity must not UPDATE');
    }

    public function testBoolDecodeCoversPgAndMysqlLiterals(): void
    {
        $t = $this->hydratedCasted($this->fullRow(['published' => 't']));
        $f = $this->hydratedCasted($this->fullRow(['published' => '0', 'id' => '2']));
        $this->assertTrue($t->published);
        $this->assertFalse($f->published);
    }

    public function testBoolDecodeThrowsOnUnknownLiteral(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->hydratedCasted($this->fullRow(['published' => 'maybe']));
    }

    /* ---------------------------------------------------------- json */

    public function testJsonDecodeOnHydrate(): void
    {
        $e = $this->hydratedCasted($this->fullRow(['tags' => '["a","b"]']));

        $this->assertSame(['a', 'b'], $e->tags);

        // Snapshot holds the RAW string — diff stays `!==` on scalars.
        $node = $this->em->heap()->find($e);
        $this->assertSame('["a","b"]', $node->data['tags']);
    }

    public function testJsonEncodeOnInsertAndNoPhantomUpdate(): void
    {
        $e = new CastedArticle();
        $e->id     = 1;
        $e->title  = 'T';
        $e->tags   = ['a', 'b'];
        $e->labels = ['x'];

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];
        $this->assertStringContainsString('INSERT INTO', $q['sql']);
        $this->assertSame(['a', 'b'], json_decode($q['params'][2], true));
        $this->assertSame('{x}', $q['params'][3], 'pgarray literal on insert');

        // Second flush: snapshot holds the encoded strings, diff clean.
        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame(1, count($this->dataQueries()), 'no UPDATE after re-persist');
    }

    public function testJsonDecodeRoundTripThroughFind(): void
    {
        $this->db->setMockResults([
            [
                [
                    'id'          => 1,
                    'title'       => 'T',
                    'tags'        => '{"x":1}',
                    'price_cents' => null,
                    'rating'      => null,
                    'published'   => null,
                    'labels'      => null,
                    'slug'        => null,
                ]
            ]
        ]);

        $e = $this->em->find(CastedArticle::class, ['id' => 1]);

        $this->assertSame(['x' => 1], $e->tags);
    }

    public function testJsonDecodeThrowsOnCorruptStoredJson(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->hydratedCasted($this->fullRow(['tags' => '{not json']));
    }

    /* ------------------------------------------------------- pgarray */

    public function testPgArrayDecodeParsesQuotedAndNullElements(): void
    {
        $e = $this->hydratedCasted($this->fullRow([
            'labels' => '{1,"a b","c\\"d",NULL,2.5,t}',
        ]));

        $this->assertSame([1, 'a b', 'c"d', null, 2.5, true], $e->labels);
    }

    public function testPgArrayDecodeIgnoresWhitespaceAndCoercesQuotedStrings(): void
    {
        // pg's lexer skips whitespace between tokens; quoted content keeps
        // its type (string) even when it looks numeric/bool-ish.
        $e = $this->hydratedCasted($this->fullRow([
            'labels' => '{ 1, "42" , "NULL" , t , "  padded  " }',
        ]));

        $this->assertSame([1, '42', 'NULL', true, '  padded  '], $e->labels);
    }

    public function testPgArrayEncodeQuotesAndEscapes(): void
    {
        $e = new CastedArticle();
        $e->id     = 1;
        $e->labels = ['a b', 'c"d', null, '', 'NULL'];

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];
        $this->assertStringContainsString('INSERT INTO', $q['sql']);
        // 'NULL' string MUST be quoted (ambiguity), real null stays NULL el.
        // Only id + labels are set → labels is the second bound param.
        $this->assertSame(
            '{"a b","c\\"d",NULL,"","NULL"}',
            $q['params'][1]
        );
    }

    public function testPgArrayEncodesAndRoundTripsNestedArrays(): void
    {
        $e = new CastedArticle();
        $e->id     = 1;
        $e->labels = [[1, 2], [3, null]];

        $this->em->persist($e);
        $this->em->flush();

        // pg 2-D literal; dimension regularity is validated server-side
        // (ragged shapes bind, then pg rejects the INSERT).
        $q = $this->dataQueries()[0];
        $this->assertSame('{{1,2},{3,NULL}}', $q['params'][1]);

        // decode() parses the full grammar back to nested arrays.
        $round = FastHydrator::for(CastedArticle::class)
            ->hydrate($this->em->heap(), ['id' => 2, 'labels' => '{{1,2},{3,NULL}}']);
        $this->assertSame([[1, 2], [3, null]], $round[0]->labels);
    }

    public function testPgArrayThrowsBeyondSixDimensions(): void
    {
        $e = new CastedArticle();
        $e->id = 1;
        // 7-deep: pg's own dimension limit.
        $e->labels = [[[[[[[1]]]]]]];

        $this->expectException(\RuntimeException::class);
        $this->em->persist($e);
        $this->em->flush();
    }

    public function testPgArrayEmptyArrayEncodesEmptyLiteral(): void
    {
        $e = new CastedArticle();
        $e->id     = 1;
        $e->labels = [];

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];
        $this->assertSame('{}', $q['params'][1]);
    }

    /* ------------------------------------------------- custom casts */

    public function testCustomCastRegistrationAndDecode(): void
    {
        Casts::register('upper', new class implements \Azera\Orm\Casting\Cast
        {
            public function encode(mixed $value): mixed
            {
                return $value;
            }
            public function decode(mixed $value): mixed
            {
                return $value === null ? null : strtoupper((string) $value);
            }
        });

        FastHydrator::clear(); // recompile plan including the new cast

        $e = $this->hydratedCasted($this->fullRow(['slug' => 'hello']));

        $this->assertSame('HELLO', $e->slug);
    }

    /* ------------------------------------------------------- datetime */

    public function testDateTimeEncodeFormatsInterfaceAndDecodeGivesImmutable(): void
    {
        $e = new CastedArticle();
        $e->id           = 1;
        $e->published_at = new \DateTimeImmutable('2026-09-05 12:30:45');

        $this->em->persist($e);
        $this->em->flush();

        $q = $this->dataQueries()[0];
        $this->assertSame('2026-09-05 12:30:45', $q['params'][1]);

        // Hydration decodes the stored text back to an immutable instance.
        $h = $this->hydratedCasted($this->fullRow(['published_at' => '2026-09-05 12:30:45']));
        $this->assertInstanceOf(\DateTimeImmutable::class, $h->published_at);
        $this->assertSame('2026-09-05 12:30:45', $h->published_at->format('Y-m-d H:i:s'));
    }

    public function testDateTimeHydratedUnchangedEntityPersistsNothing(): void
    {
        $e = $this->hydratedCasted($this->fullRow(['published_at' => '2026-09-05 12:30:45']));

        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame([], $this->dataQueries(), 'unchanged hydrated datetime must not UPDATE');
    }

    public function testDateTimeDecodeThrowsOnUnparseableValue(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->hydratedCasted($this->fullRow(['published_at' => 'not-a-date']));
    }

    public function testDateTimeCastIsReplaceable(): void
    {
        Casts::register('datetime', new class implements \Azera\Orm\Casting\Cast
        {
            public function encode(mixed $value): mixed
            {
                return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
            public function decode(mixed $value): mixed
            {
                if ($value === null || !\is_string($value)) {
                    return $value;
                }
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                return $parsed === false ? null : $parsed;
            }
        });

        FastHydrator::clear(); // recompile plan with the replacement

        $e = new CastedArticle();
        $e->id           = 1;
        $e->published_at = new \DateTimeImmutable('2026-09-05 12:30:45');

        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame('2026-09-05', $this->dataQueries()[0]['params'][1]);
    }

    /* ------------------------------------------ cast-free fast path */

    public function testPlainClassKeepsRawValues(): void
    {
        $this->db->setMockResults([[['id' => '5', 'title' => 'T']]]);

        $e = $this->em->find(PlainArticle::class, ['id' => 5]);

        // No casts compiled for PlainArticle — raw passthrough.
        $this->assertSame('5', $e->id);
    }

    /* ------------------------------------------------- nullability */

    private function hydrator(): FastHydrator
    {
        return FastHydrator::for(NullabilityShapes::class);
    }

    /** @return array<string, mixed> a full row with every column present */
    private function nullRow(array $overrides = []): array
    {
        return array_merge([
            'id'            => 1,
            'from_php_type' => null,
            'untyped'       => null,
            // Non-nullable columns carry real values; tests that want a
            // NULL override them explicitly.
            'untyped_not_nullable' => 'set',
            'plain'                => 0,
            'raw_int'              => 0,
            'raw_untyped_int'      => 0,
        ], $overrides);
    }

    public function testNullablePhpTypeAcceptsNull(): void
    {
        [$e] = $this->hydrator()->hydrate($this->em->heap(), $this->nullRow());

        $this->assertNull($e->from_php_type);
        $this->assertNull($e->untyped);
    }

    /**
     * A NULL for a NOT-nullable column is a schema/type mismatch — the
     * error names the class, the property, and the remedy instead of
     * letting PHP raise a bare TypeError at the assignment.
     */
    public function testNullIntoNonNullableColumnThrowsDiagnosableError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot hydrate NULL into');

        $this->hydrator()->hydrate($this->em->heap(), $this->nullRow(['plain' => null]));
    }

    public function testNullIntoUntypedNonNullableColumnThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('untyped_not_nullable');

        $this->hydrator()->hydrate(
            $this->em->heap(),
            $this->nullRow(['untyped_not_nullable' => null])
        );
    }

    /**
     * The phantom-UPDATE regression: a TYPED property whose cast is
     * suppressed. PHP coerces the driver's '1200' to int(1200) on
     * assignment, so the snapshot must record int(1200) — a raw-string
     * snapshot would differ from extractData() forever.
     */
    public function testCastFalseOnTypedNumericDoesNotSchedulePhantomUpdate(): void
    {
        [$e] = $this->hydrator()->hydrate(
            $this->em->heap(),
            $this->nullRow(['raw_int' => '1200'])
        );

        $this->assertSame(1200, $e->raw_int);

        $node = $this->em->heap()->find($e);
        $this->assertSame(1200, $node->data['raw_int']);

        // Re-persist UNCHANGED: nothing may be written.
        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame([], $this->dataQueries(), 'unchanged cast:false entity must not UPDATE');
    }

    /**
     * The mirror case: an UNTYPED property with cast suppressed keeps the
     * driver string in BOTH the property and the snapshot, so it is also
     * diff-clean (and binds back as the original string).
     */
    public function testCastFalseOnUntypedNumericKeepsRawStringAndStaysClean(): void
    {
        [$e] = $this->hydrator()->hydrate(
            $this->em->heap(),
            $this->nullRow(['raw_untyped_int' => '1200'])
        );

        $this->assertSame('1200', $e->raw_untyped_int);

        $node = $this->em->heap()->find($e);
        $this->assertSame('1200', $node->data['raw_untyped_int']);

        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame([], $this->dataQueries());
    }

    /* ------------------------------------------------ strict decoding */

    /**
     * `"abc"` used to decode to int(0)/float(0.0) — indistinguishable
     * from a real zero, and written back over the original value.
     */
    public function testNonNumericStringIsRejectedForIntColumn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid integer string');

        $this->hydratedCasted($this->fullRow(['price_cents' => 'abc']));
    }

    /* ------------------------------------------------ all-raw fast path */

    /**
     * PlainArticle is the all-raw shape (every property untyped, no
     * casts) — hydrate()'s raw fast path. The fast path must behave
     * EXACTLY like the put() gate: snapshot equals the assigned values,
     * NULL contract enforced for non-nullable columns.
     */
    public function testAllRawFastPathHydratesAndSnapshotsRawCells(): void
    {
        $heap = $this->em->heap();

        [$entity, $id, $data] = FastHydrator::for(PlainArticle::class)->hydrate(
            $heap,
            ['id' => '5', 'title' => 'T']
        );

        // Values assigned as-is (untyped props cannot coerce).
        $this->assertSame('5', $entity->id);
        $this->assertSame('T', $entity->title);

        // Snapshot keyed by COLUMN name, values identical to the row
        // cells — the diff-clean baseline put() guarantees.
        $this->assertSame(['id' => '5', 'title' => 'T'], $data);
        $this->assertSame($data, $heap->find($entity)->data);

        // No phantom UPDATE on the first persist of the unchanged entity.
        $this->em->persist($entity);
        $this->em->flush();
        $this->assertSame([], $this->dataQueries());

        // NULL into a NON-nullable column is rejected on the fast path
        // too — the gate is inlined, not dropped. (The PK orphan guard
        // can never exercise this: a null PK no-ops the whole row.)
        try {
            FastHydrator::for(PlainGuarded::class)->hydrate(
                $heap,
                ['id' => '9', 'title' => 'X', 'untyped_not_nullable' => null]
            );
            $this->fail('Expected LogicException for NULL into non-nullable column');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('not nullable', $e->getMessage());
        }
    }

    /**
     * `--42` must NOT decode to 0: stripping a sign RUN (ltrim '+-') let
     * any doubled-sign garbage through the digit check while `(int)` had
     * already produced 0 — the exact silent-zero corruption the strict
     * cast exists to reject. Exactly ONE sign is legal.
     */
    public function testSignRunIsRejectedForIntColumn(): void
    {
        foreach (['--42', '+-42', '-+42', '++42'] as $garbage) {
            try {
                $this->hydratedCasted($this->fullRow(['id' => 900, 'price_cents' => $garbage]));
                $this->fail("Expected rejection for {$garbage}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('invalid integer string', $e->getMessage());
            }
        }
    }

    public function testNonNumericStringIsRejectedForFloatColumn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a well-formed number');

        $this->hydratedCasted($this->fullRow(['rating' => 'abc']));
    }

    public function testFractionalFloatIsRejectedForIntColumn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('loss of precision');

        $this->hydratedCasted($this->fullRow(['price_cents' => 12.5]));
    }

    public function testWellFormedNumericFormsStillDecode(): void
    {
        $e = $this->hydratedCasted($this->fullRow([
            'price_cents' => '  -42 ',
            'rating'      => '1e3',
        ]));

        $this->assertSame(-42, $e->priceCents);
        $this->assertSame(1000.0, $e->rating);

        // Integral float for an int column is legitimate (RETURNING/BSON).
        $e2 = $this->hydratedCasted($this->fullRow(['id' => 2, 'price_cents' => 1200.0]));
        $this->assertSame(1200, $e2->priceCents);
    }

    /**
     * ZERO must decode for every spelling the three drivers actually
     * produce — measured 2026-09-19:
     *
     *   sqlite                 int 0 / float 0.0   (never stringified)
     *   pgsql                  '0'                 (always text)
     *   mysql emulated         '0'
     *   mysql native           int 0 / float 0.0
     *   mysql DECIMAL column   '0.00'
     *
     * A zero-literal allowlist is a trap here: '0e0', '.0' and '0.' are
     * all reachable too. Validating the input FORM (is_numeric / digit
     * regex) covers every spelling at once.
     */
    public function testZeroDecodesForEveryDriverSpelling(): void
    {
        // Distinct ids: the heap is identity-mapped within a test, so
        // reusing id 1 would return the FIRST hydrated instance.
        $id = 100;

        foreach (['0', '0.0', '0.00', '0e0', '.0', '0.', ' 0 '] as $spelling) {
            $e = $this->hydratedCasted($this->fullRow(['id' => $id++, 'rating' => $spelling]));
            $this->assertSame(0.0, $e->rating, "float spelling {$spelling}");
        }

        // Native-typed floats (sqlite / mysql-native).
        $e = $this->hydratedCasted($this->fullRow(['id' => $id++, 'rating' => 0.0]));
        $this->assertSame(0.0, $e->rating);

        // Int column: digit form (pgsql/emulated) and native int (sqlite).
        foreach (['0', '-0', '+0', '000'] as $spelling) {
            $e = $this->hydratedCasted($this->fullRow(['id' => $id++, 'price_cents' => $spelling]));
            $this->assertSame(0, $e->priceCents, "int spelling {$spelling}");
        }

        $e = $this->hydratedCasted($this->fullRow(['id' => $id++, 'price_cents' => 0]));
        $this->assertSame(0, $e->priceCents);
    }

    /**
     * Native-typed zero (sqlite, mysql native prepares) takes the fast
     * path — no string parsing, and the value is preserved exactly.
     */
    public function testNativeTypedValuesTakeTheFastPath(): void
    {
        $e = $this->hydratedCasted($this->fullRow([
            'price_cents' => 7,
            'rating'      => 4.5,
        ]));

        $this->assertSame(7, $e->priceCents);
        $this->assertSame(4.5, $e->rating);

        // And the snapshot matches, so no phantom UPDATE on sqlite.
        $node = $this->em->heap()->find($e);
        $this->assertSame(7, $node->data['price_cents']);
        $this->assertSame(4.5, $node->data['rating']);

        $this->em->persist($e);
        $this->em->flush();

        $this->assertSame([], $this->dataQueries());
    }

    public function testCastsRegistryBuiltins(): void
    {
        $this->assertSame(
            ['int', 'float', 'bool', 'json', 'pgarray', 'datetime'],
            Casts::types()
        );

        $this->assertInstanceOf(DateTimeCast::class, Casts::for('datetime'));
    }
}