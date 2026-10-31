<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';

use Azera\Db\Query;
use Azera\Tests\Db\TestPgDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the ORDER BY / column-list injection of the 2026
 * security review. In model mode the previous validation only inspected the
 * first whitespace token, and `protectIdentifier()` passed anything with
 * parentheses through verbatim.
 */
class QueryIdentifierValidationRegressionTest extends TestCase
{
    private function query(): Query
    {
        return Query::modelFor(\Azera\Tests\Orm\Fixtures\Article::class, new TestPgDatabase());
    }

    /* ----------------------------------------------------------- orderBy() */

    public function testOrderBySubSelectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->orderBy(
            "id (CASE WHEN (SELECT password FROM users WHERE id=1) LIKE 'H%' THEN id ELSE -id END)"
        );
    }

    public function testOrderByParenthesisExpressionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->orderBy('id, (SELECT 1)');
    }

    public function testOrderByCommentInjectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->orderBy('id--');
    }

    public function testOrderByUnknownFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->orderBy('created_at DESC, nonsense');
    }

    public function testOrderByKnownFieldsWithDirectionPass(): void
    {
        $q = $this->query()->orderBy('created_at DESC, id ASC');

        $this->assertSame(
            'SELECT * FROM "article" ORDER BY "created_at" DESC,"id" ASC',
            $q->returnSql()->select()
        );
    }

    /* ----------------------------------------------------------- columns() */

    public function testColumnsSubSelectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->columns(['id', '(SELECT password FROM users LIMIT 1) AS pw']);
    }

    public function testColumnsKnownFieldsPass(): void
    {
        $q = $this->query()->columns(['id', 'title AS name']);

        $sql = $q->returnSql()->select();

        $this->assertStringContainsString('"id"', $sql);
        $this->assertStringContainsString('"title"', $sql);
    }

    /* ----------------------------------------------------------- groupBy() */

    public function testGroupBySubSelectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->groupBy('(SELECT 1)');
    }

    public function testGroupByKnownFieldPasses(): void
    {
        $q = $this->query()->groupBy('id');

        $this->assertStringContainsString('GROUP BY "id"', $q->returnSql()->select());
    }

    /**
     * Raw-table queries have no metadata to validate against; the escape
     * hatch is explicitly documented, so this must keep working.
     */
    public function testRawQueryOrderByStillPermitsExpressions(): void
    {
        $q = Query::raw(new TestPgDatabase())
            ->table('articles')
            ->orderBy('price * quantity DESC');

        $this->assertStringContainsString('ORDER BY', $q->returnSql()->select());
    }

    /**
     * In model mode an expression must still be expressible through a `Sql`
     * node — that is the sanctioned route, not a string.
     */
    public function testModelOrderByAcceptsSqlNode(): void
    {
        $sql = $this->query()->orderBy([\Azera\Db\Sql::raw('RANDOM()')])->returnSql()->select();

        $this->assertStringContainsString('ORDER BY RANDOM()', $sql);
    }

    public function testModelColumnsAcceptSqlNode(): void
    {
        $sql = $this->query()
            ->columns([\Azera\Db\Sql::raw('COUNT(*)')->as('n')])
            ->returnSql()
            ->select();

        $this->assertStringContainsString('COUNT(*)', $sql);
    }

    public function testModelGroupByAcceptsSqlNode(): void
    {
        $sql = $this->query()
            ->groupBy([\Azera\Db\Sql::raw('DATE(created_at)')])
            ->returnSql()
            ->select();

        $this->assertStringContainsString('GROUP BY DATE(created_at)', $sql);
    }
}
