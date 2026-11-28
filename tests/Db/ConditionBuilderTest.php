<?php
namespace Azera\Tests\Db;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/TestDatabase.php';

use Azera\Db\Sql;
use Azera\Db\Condition;
use Azera\Db\Query;
use PHPUnit\Framework\TestCase;

class ConditionBuilderTest extends TestCase
{
    public function testSimpleWhereEscapedLiteral(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('id', 123);
        $this->assertEquals('("id" = 123)', $cb->toSql());
    }

    public function testNamedPlaceholderReplacement(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('col = :v', ['v' => 'foo']);
        $this->assertEquals("(col = 'foo')", $cb->toSql());
    }

    public function testBetweenWhere(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->betweenWhere('age', 18, 30);
        $this->assertEquals('("age" BETWEEN 18 AND 30)', $cb->toSql());
    }

    public function testInWhereArray(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->inWhere('id', [1, 2, 3]);
        $this->assertEquals('("id" IN (1,2,3))', $cb->toSql());
    }

    public function testGroupingWithOr(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('a', 1)
            ->group(function (Condition $g) {
                $g->where('b', 2)
                    ->orWhere('c', 3);
            });

        $cb->group(function (Condition $g) {
            $g->where('d', 4)
                ->orWhere('e', 5);
        });

        $this->assertEquals('("a" = 1) AND (("b" = 2) OR ("c" = 3)) AND (("d" = 4) OR ("e" = 5))', $cb->toSql());
    }

    public function testOrGroup(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('a', 1)
            ->orGroup(
                function (Condition $g) {
                    $g->where('b', 2)
                        ->where('c', 3);
                }
            );

        $this->assertEquals('("a" = 1) OR (("b" = 2) AND ("c" = 3))', $cb->toSql());
    }

    public function testNotGroup(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('a', 1)
            ->notGroup(
                function (Condition $g) {
                    $g->where('b', 2)
                        ->orWhere('c', 3);
                }
            );

        $this->assertEquals('("a" = 1) AND NOT (("b" = 2) OR ("c" = 3))', $cb->toSql());
    }

    public function testOrNotGroup(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('a', 1)
            ->orNotGroup(
                function (Condition $g) {
                    $g->where('b', 2)
                        ->orWhere('c', 3);
                }
            );

        $this->assertEquals('("a" = 1) OR NOT (("b" = 2) OR ("c" = 3))', $cb->toSql());
    }

    public function testNestedGroupsWithCallback(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('a', 1)
            ->group(
                function (Condition $g) {
                    $g->where('b', 2)
                        ->orGroup(
                            function (Condition $g2) {
                                $g2->where('c', 3)
                                    ->where('d', 4);
                            }
                        );
                }
            );

        $this->assertEquals(
            '("a" = 1) AND (("b" = 2) OR (("c" = 3) AND ("d" = 4)))',
            $cb->toSql()
        );
    }

    public function testGroupStartGroupEndStillWork(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->where('a', 1)
            ->group(
                fn(Condition $g) =>
                    $g->where('b', 2)
                        ->orWhere('c', 3)
            );

        $this->assertEquals('("a" = 1) AND (("b" = 2) OR ("c" = 3))', $cb->toSql());
    }

    public function testConditionCreate(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        $cb->where('id', 123);
        $this->assertEquals('("id" = 123)', $cb->toSql());
    }

    public function testQualifiedIdentifiers(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        $cb->where('users.id', 123);
        $this->assertEquals('("users"."id" = 123)', $cb->toSql());
    }

    public function testLikeWithQualifiedIdentifier(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        $cb->likeWhere('u.name', 'john%');
        $this->assertEquals('("u"."name" LIKE \'john%\')', $cb->toSql());
    }

    public function testBetweenWithQualifiedIdentifier(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        $cb->betweenWhere('u.age', 18, 30);
        $this->assertEquals('("u"."age" BETWEEN 18 AND 30)', $cb->toSql());
    }

    public function testInWithQualifiedIdentifier(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        $cb->inWhere('u.status', [1, 2, 3]);
        $this->assertEquals('("u"."status" IN (1,2,3))', $cb->toSql());
    }

    public function testLargeInList(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        // Test with a large list (2000 items) - this would have caused PCRE failures before
        $largeList = range(1, 2000);
        $cb->inWhere('id', $largeList);

        $result = $cb->toSql();
        $this->assertStringContainsString('("id" IN (', $result);
        $this->assertStringContainsString('2000', $result);
    }

    public function testPlainConditionStringWithEquals(): void
    {
        $db = new TestPgDatabase();
        $cb = Condition::new($db);

        $cb->where('u.id = o.user_id');
        $this->assertEquals('("u"."id" = "o"."user_id")', $cb->toSql());
    }

    public function testHavingWithSqlPlaceholder(): void
    {
        $db = new TestPgDatabase();
        $cb = new Condition($db);

        $cb->having(
            ':count > :min_orders',
            [
                'count'      => Sql::func('COUNT', [Sql::column('o.id')]),
                'min_orders' => 5
            ]
        );

        $this->assertEquals('(HAVING COUNT("o"."id") > 5)', $cb->toSql());
    }

    /**
     * A literal zero on the right-hand side must survive.
     *
     * The identifier guard used `!empty($rhs)`, which is false for the STRING
     * '0' and true for every other non-empty string — so `x = 0` and
     * `tenant_id > 0` compiled to `("x" =)` / `("tenant_id" >)`, silently
     * dropping the value and leaving a statement the driver rejects.
     */
    public function testAZeroRightHandSideIsNotDropped(): void
    {
        $db = new TestPgDatabase();

        foreach (['x = 0', 'tenant_id > 0', 'a <> 0', 'amount >= 6'] as $condition) {
            $sql = Query::raw($db)->returnSql()->table('items')->where($condition)->select();

            $this->assertStringNotContainsString('=)', $sql, $condition);
            $this->assertStringNotContainsString('>)', $sql, $condition);
        }

        $this->assertStringContainsString(
            '("tenant_id" > 0)',
            Query::raw($db)->returnSql()->table('items')->where('tenant_id > 0')->select(),
        );
        $this->assertStringContainsString(
            '("x" = 0)',
            Query::raw($db)->returnSql()->table('items')->where('x = 0')->select(),
        );
    }

    /** IS NULL has no right-hand side; the zero fix must not add a space+dash. */
    public function testANullTestKeepsItsShape(): void
    {
        $db = new TestPgDatabase();

        $this->assertStringContainsString(
            '("deleted_at" IS NULL)',
            Query::raw($db)->returnSql()->table('items')->where('deleted_at IS NULL')->select(),
        );
    }
}