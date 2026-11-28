<?php
namespace Azera\Tests\Db;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/TestDatabase.php';

use Azera\AppContext;
use Azera\Db\Query;
use Azera\Db\Sql;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Derived-shape UPSERT regression tests.
 *
 * With NO explicit updateValues(), compileInsert() must derive the SET
 * clause from the INSERT columns as EXCLUDED refs (sqlite/pgsql) or
 * VALUES() refs (mysql), EXCLUDING the conflict target. Writing the PK
 * (or rebinding literals) into SET instead forces SQLite to compile the
 * conflict action as an internal DELETE+INSERT — fsync-bound (~1.2-5.7ms
 * vs ~8µs per statement on WAL SQLite). These tests pin the fast shape
 * so the builder can't regress into the slow one.
 */
class UpsertShapeTest extends TestCase
{
    protected function setUp(): void
    {
        AppContext::setInstance(new AppContext());
    }

    /** Raw sqlite upsert: PK first in the INSERT column list, no conflict() call. */
    public function testSqliteDerivedShapeExcludesFirstColumnAsTarget(): void
    {
        $db = new TestSqliteDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->upsert([
                'id'         => 999999,
                'title'      => 'Created Item',
                'created_at' => '2026-09-05 12:00:00',
            ]);

        $this->assertStringContainsString('INSERT INTO "items"', $sql);
        // The inferred target is now EMITTED rather than omitted. Leaving it
        // out makes the upsert fire on ANY violated uniqueness constraint —
        // the wrong row, or a spurious conflict — and makes a partial index
        // unreachable. Verified against SQLite 3.53.4.
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET ', $sql);

        // The fast shape: EXCLUDED refs for the non-PK columns, and the PK
        // ("id") must NEVER appear on the left side of a SET assignment.
        $this->assertStringContainsString('"title"=EXCLUDED."title"', $sql);
        $this->assertStringContainsString('"created_at"=EXCLUDED."created_at"', $sql);
        $this->assertMatchesRegularExpression('/DO UPDATE SET (?!.*"id"\s*=)/', $sql);

        // Literally-stable: no value literals may leak into the SET clause.
        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringNotContainsString('Created Item', $set);
        $this->assertStringNotContainsString('2026-09-05', $set);
    }

    /** Raw sqlite upsert with an explicit conflict() column list. */
    public function testSqliteExplicitConflictTargetIsExcludedFromSet(): void
    {
        $db = new TestSqliteDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('users')
            ->conflict(['email'])
            ->upsert([
                'email' => 'john@example.com',
                'name'  => 'John',
            ]);

        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringContainsString('"name"=EXCLUDED."name"', $set);
        $this->assertStringNotContainsString('"email"', $set);
    }

    /** Model-backed query: the metadata idFields() are the implicit target. */
    public function testModelModeExcludesIdFieldsFromDerivedSet(): void
    {
        $db = new TestSqliteDatabase();
        AppContext::instance()->dbManager()->set('default', $db);

        $sql = DummyUpsertModel::query()->returnSql()->upsert([
            'id'   => 5,
            'name' => 'Echo',
        ]);

        $this->assertStringContainsString('INSERT INTO "dummy_upsert_model"', $sql);
        $this->assertStringContainsString('DO UPDATE SET', $sql);
        $this->assertStringContainsString('"name"=EXCLUDED."name"', $sql);
        $this->assertStringNotContainsString('"id"=', $sql);
    }

    /** MySQL uses the VALUES(col) dialect in the derived shape. */
    public function testMysqlDerivedShapeUsesValuesRefs(): void
    {
        $db = new TestMysqlDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->upsert([
                'id'    => 999999,
                'title' => 'Created Item',
            ]);

        $this->assertStringContainsString(' ON DUPLICATE KEY UPDATE ', $sql);
        $this->assertStringContainsString('`title`=VALUES(`title`)', $sql);
        $set = substr($sql, (int) strpos($sql, 'ON DUPLICATE KEY UPDATE '));
        $this->assertStringNotContainsString('`id`', $set);
    }

    /** PostgreSQL derives the target from explicit conflict() too. */
    public function testPgsqlDerivedShapeExcludesConflictTarget(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->conflict(['id'])
            ->upsert([
                'id'    => 999999,
                'title' => 'Created Item',
            ]);

        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $sql);
        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringContainsString('"title"=EXCLUDED."title"', $set);
        $this->assertStringNotContainsString('"id"', $set);
    }

    /** Explicit list updateValues keeps the legacy documented behavior. */
    public function testExplicitListUpdateValuesStillWinsOverDerivation(): void
    {
        $db = new TestSqliteDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->conflict(['id'])
            ->updateValues(['title', 'created_at'])
            ->upsert([
                'id'         => 999999,
                'title'      => 'Created Item',
                'created_at' => '2026-09-05 12:00:00',
            ]);

        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertSame('DO UPDATE SET "title"=EXCLUDED."title","created_at"=EXCLUDED."created_at"', trim($set));
    }

    /** Explicit assoc updateValues (incl. custom Sql expressions) is untouched. */
    public function testExplicitAssocUpdateValuesStillWinsOverDerivation(): void
    {
        $db = new TestSqliteDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('posts')
            ->conflict(['slug'])
            ->updateValues([
                'title'      => 'My Article',
                'view_count' => Sql::raw('view_count + 1'),
            ])
            ->upsert([
                'slug'  => 'my-article',
                'title' => 'My Article',
            ]);

        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringContainsString("'My Article'", $set);
        $this->assertStringContainsString('view_count + 1', $set);
    }

    /** Named-bind upserts: returnSql() interpolates the bound values; the
     * PK must still never appear in the SET clause. */
    public function testManualBindingsKeepPlaceholderSetShape(): void
    {
        $db = new TestSqliteDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->conflict(['id'])
            ->bind(['id' => 1, 'title' => 'Bound'])
            ->upsert();

        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringContainsString('"title"=', $set);
        $this->assertStringNotContainsString('"id"=', $set);
    }

    /** Derived shape with EVERY column in the conflict target is unusable. */
    public function testAllColumnsInTargetThrows(): void
    {
        $db = new TestSqliteDatabase();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no columns to update');

        Query::raw($db)
            ->returnSql()
            ->table('kv')
            ->conflict(['k', 'v'])
            ->upsert(['k' => 'a', 'v' => 'b']);
    }

    /* ================================================ partial indexes
     *
     * A partial unique index is inferred from an ON CONFLICT target only when
     * the statement repeats the index predicate. Without it the statement is
     * rejected outright, so conflictWhere() is what makes the target usable at
     * all for that index shape. Verified against PostgreSQL 18 and
     * SQLite 3.53.4.
     */

    public function testConflictWhereEmitsThePartialIndexPredicate(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflict(['stripe_customer_id'])
            ->conflictWhere('stripe_customer_id IS NOT NULL')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);

        $this->assertStringContainsString(
            'ON CONFLICT ("stripe_customer_id") WHERE "stripe_customer_id" IS NOT NULL DO UPDATE SET',
            $sql,
        );
    }

    /**
     * The predicate is emitted VERBATIM — it is DDL-equivalent structure.
     *
     * Rendering it through the where()-condition quoter was a bug: that helper
     * mangles what it does not recognise, e.g. `x > 0` loses the `0`, so a
     * predicate copied out of `CREATE INDEX … WHERE` could reach PostgreSQL as
     * invalid SQL. PostgreSQL matches the predicate semantically, so verbatim is
     * both correct and predictable.
     */
    public function testConflictWhereEmitsThePredicateVerbatim(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflict(['stripe_customer_id'])
            ->conflictWhere('deleted_at IS NULL AND "kind" IN (\'a\',\'b\')')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);

        $this->assertStringContainsString(
            'WHERE "deleted_at" IS NULL AND "kind" IN (\'a\',\'b\') DO UPDATE SET',
            $sql,
        );
    }

    /** The target column stays out of the SET clause on the partial path too. */
    public function testConflictWhereKeepsTheTargetOutOfTheSetClause(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflict(['stripe_customer_id'])
            ->conflictWhere('stripe_customer_id IS NOT NULL')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);

        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringContainsString('"email"=EXCLUDED."email"', $set);
        $this->assertStringNotContainsString('"stripe_customer_id"=', $set);
    }

    /** sqlite takes the same target + predicate (verified on 3.53.4). */
    public function testSqliteEmitsTheTargetAndPredicate(): void
    {
        $db = new TestSqliteDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('customers')
            ->conflict(['stripe_customer_id'])
            ->conflictWhere('stripe_customer_id IS NOT NULL')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);

        $this->assertStringContainsString(
            'ON CONFLICT ("stripe_customer_id") WHERE "stripe_customer_id" IS NOT NULL DO UPDATE SET',
            $sql,
        );
    }

    /**
     * The two calls are order-independent.
     *
     * An earlier implementation classified a STRING target as "constraint name
     * or column list" by consulting the predicate, so calling conflictWhere()
     * first turned `conflict('email')` into an ON CONSTRAINT and produced SQL
     * that could not run. Classification is now purely syntactic.
     */
    public function testConflictAndConflictWhereAreOrderIndependent(): void
    {
        $db = new TestPgDatabase();

        $build = static fn(bool $predicateFirst): string => $predicateFirst
            ? Query::raw($db)->returnSql()->table('customers')
                ->conflictWhere('deleted_at IS NULL')
                ->conflict(['email'])
                ->upsert(['email' => 'a@example.com', 'name' => 'A'])
            : Query::raw($db)->returnSql()->table('customers')
                ->conflict(['email'])
                ->conflictWhere('deleted_at IS NULL')
                ->upsert(['email' => 'a@example.com', 'name' => 'A']);

        $this->assertSame($build(false), $build(true));
        $this->assertStringContainsString(
            'ON CONFLICT ("email") WHERE "deleted_at" IS NULL DO UPDATE SET',
            $build(true),
        );
    }

    /**
     * The single-column STRING form must not be mistaken for a constraint name.
     * A string is always a column list, so this compiles in either call order —
     * the earlier syntactic guess turned it into an ON CONSTRAINT whenever the
     * predicate came second.
     */
    public function testASingleColumnStringTargetWorksInEitherOrder(): void
    {
        $db = new TestPgDatabase();

        $after = Query::raw($db)->returnSql()->table('customers')
            ->conflict('email')
            ->conflictWhere('deleted_at IS NULL')
            ->upsert(['email' => 'a@example.com', 'name' => 'A']);

        $before = Query::raw($db)->returnSql()->table('customers')
            ->conflictWhere('deleted_at IS NULL')
            ->conflict('email')
            ->upsert(['email' => 'a@example.com', 'name' => 'A']);

        $expected = 'ON CONFLICT ("email") WHERE "deleted_at" IS NULL DO UPDATE SET';

        $this->assertStringContainsString($expected, $after);
        $this->assertStringContainsString($expected, $before);
        $this->assertSame($after, $before);
        $this->assertStringNotContainsString('ON CONSTRAINT', $after);
    }

    /** The same for the comma-separated string shorthand. */
    public function testConflictWhereBeforeACommaSeparatedStringTargetIsOrderIndependent(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->conflictWhere('deleted_at IS NULL')
            ->conflict('tenant_id, item_id')
            ->upsert(['tenant_id' => 1, 'item_id' => 2, 'name' => 'X']);

        $this->assertStringContainsString(
            'ON CONFLICT ("tenant_id","item_id") WHERE "deleted_at" IS NULL',
            $sql,
        );
        $this->assertStringNotContainsString('ON CONSTRAINT', $sql);
    }

    /**
     * DO NOTHING accepts a target and needs the predicate for a partial index,
     * otherwise the index is not inferred and the statement is rejected.
     */
    public function testIgnoreEmitsTheTargetAndPredicate(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflict(['stripe_customer_id'])
            ->conflictWhere('stripe_customer_id IS NOT NULL')
            ->ignore()
            ->insert(['stripe_customer_id' => 'cus_1']);

        $this->assertStringContainsString(
            'ON CONFLICT ("stripe_customer_id") WHERE "stripe_customer_id" IS NOT NULL DO NOTHING',
            $sql,
        );
    }

    /** Plain ignore() is untouched: no target, no predicate. */
    public function testIgnoreWithoutATargetIsUnchanged(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->ignore()
            ->insert(['stripe_customer_id' => 'cus_1']);

        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sql);
    }

    /**
     * `ON CONFLICT ON CONSTRAINT c WHERE …` is a syntax error, and the column
     * form needs the indexed COLUMNS — which a constraint name does not supply.
     * The pair is refused rather than guessed at, in either call order.
     */
    public function testAConstraintNamedTargetWithAPredicateIsRefused(): void
    {
        $db = new TestPgDatabase();

        $this->expectException(\LogicException::class);

        Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflictConstraint('idx_billing_customers_stripe_customer_id')
            ->conflictWhere('stripe_customer_id IS NOT NULL')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);
    }

    /** The same refusal holds when the predicate is supplied first. */
    public function testAConstraintNamedTargetWithAPredicateIsRefusedInEitherOrder(): void
    {
        $db = new TestPgDatabase();

        $this->expectException(\LogicException::class);

        Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflictWhere('stripe_customer_id IS NOT NULL')
            ->conflictConstraint('idx_billing_customers_stripe_customer_id')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);
    }

    /** Without a predicate the ON CONSTRAINT form is still emitted as such. */
    public function testAConstraintNamedTargetKeepsTheOnConstraintForm(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('billing.customers')
            ->conflictConstraint('customers_stripe_customer_id_key')
            ->upsert(['stripe_customer_id' => 'cus_1', 'email' => 'a@example.com']);

        $this->assertStringContainsString('ON CONFLICT ON CONSTRAINT "customers_stripe_customer_id_key"', $sql);
    }

    /** A comma-separated column list as a string is still a column list. */
    public function testACommaSeparatedStringIsAColumnList(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('items')
            ->conflict('tenant_id, item_id')
            ->upsert(['tenant_id' => 1, 'item_id' => 2, 'name' => 'X']);

        $this->assertStringContainsString('ON CONFLICT ("tenant_id","item_id")', $sql);
        $this->assertStringNotContainsString('ON CONSTRAINT', $sql);
    }

    /** MySQL's ON DUPLICATE KEY UPDATE has no target clause at all. */
    public function testMysqlConflictWhereDoesNotLeakIntoTheStatement(): void
    {
        $db = new TestMysqlDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('customers')
            ->conflict(['email'])
            ->conflictWhere('deleted_at IS NULL')
            ->upsert(['email' => 'a@example.com', 'name' => 'A']);

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringNotContainsString('deleted_at', $sql);
    }

    /**
     * A refused combination throws without mutating the builder — the strong
     * exception guarantee — so the pending predicate is still there for a target
     * that CAN carry it. Nothing is silently discarded.
     */
    public function testARejectedNamedTargetLeavesThePendingPredicateAvailable(): void
    {
        $db = new TestPgDatabase();

        $query = Query::raw($db)->returnSql()->table('customers');

        try {
            $query->conflictWhere('deleted_at IS NULL')
                ->conflictConstraint('customers_email_key');
            $this->fail('a pending predicate combined with a named target must throw');
        } catch (LogicException) {
            // expected
        }

        // The refused call changed nothing, so the predicate still has an owner
        // in the column arm.
        $sql = $query->conflict(['email'])->upsert(['email' => 'a@example.com', 'name' => 'A']);

        $this->assertStringContainsString('ON CONFLICT ("email") WHERE "deleted_at" IS NULL', $sql);
    }

    /** DO NOTHING with an explicit named target keeps the ON CONSTRAINT form. */
    public function testIgnoreWithANamedTargetEmitsOnConstraint(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('bookings')
            ->conflictConstraint('bookings_no_overlap')
            ->ignore()
            ->insert(['room' => 'A', 'period' => 'x']);

        $this->assertStringContainsString('ON CONFLICT ON CONSTRAINT "bookings_no_overlap" DO NOTHING', $sql);
    }

    /** A named target has no column list, so no SET column can be excluded by it. */
    public function testANamedTargetLeavesTheSetClauseComplete(): void
    {
        $db = new TestPgDatabase();

        $sql = Query::raw($db)
            ->returnSql()
            ->table('bookings')
            ->conflictConstraint('bookings_no_overlap')
            ->upsert(['room' => 'A', 'period' => 'x']);

        $set = substr($sql, (int) strpos($sql, 'DO UPDATE SET '));
        $this->assertStringContainsString('"room"=EXCLUDED."room"', $set);
        $this->assertStringContainsString('"period"=EXCLUDED."period"', $set);
    }
}

class DummyUpsertModel extends \Azera\Orm\Model
{
    public $id;
    public $name;

    public function source(): string
    {
        return 'dummy_upsert_model';
    }
}