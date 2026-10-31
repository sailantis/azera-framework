<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Db/TestDatabase.php';

use Azera\Db\Condition;
use Azera\Db\Sql;
use Azera\Tests\Db\TestPgDatabase;
use Azera\Tests\Db\TestSqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the SQL-injection findings of the 2026 security review.
 *
 * Each test reproduces an exploit that succeeded before the fix, so a
 * regression fails loudly rather than silently reopening the hole.
 */
class SqlInjectionRegressionTest extends TestCase
{
    /* ------------------------------------------------ array where() places */

    /**
     * A `?` inside an already-substituted value must not become a new
     * placeholder. Before the fix the second binding was injected outside
     * its literal and the condition matched every row.
     */
    public function testPlaceholderInsideSubstitutedValueIsNotReplaced(): void
    {
        $cb = new Condition(new TestSqliteDatabase());
        $cb->where('a = ? AND b = ?', ['x?', ' OR 1=1 OR ']);

        $sql = $cb->toSql();

        // Exactly two literals, both properly closed; no dangling quote.
        $this->assertSame("(a = 'x?' AND b = ' OR 1=1 OR ')", $sql);

        // The injected payload must stay inside the second literal: it is
        // immediately preceded by an opening quote and the statement ends
        // with its closing quote.
        $this->assertStringContainsString("b = ' OR 1=1 OR ')", $sql);
        $this->assertStringEndsWith("')", $sql);
    }

    public function testPositionalPlaceholdersStillBindInOrder(): void
    {
        $cb = new Condition(new TestSqliteDatabase());
        $cb->where('a = ? AND b = ?', ['x', 'y']);

        $this->assertSame("(a = 'x' AND b = 'y')", $cb->toSql());
    }

    public function testNamedPlaceholdersStillBind(): void
    {
        $cb = new Condition(new TestSqliteDatabase());
        $cb->where('n = :name AND m = :other', ['name' => 'v1', 'other' => 'v2']);

        $this->assertSame("(n = 'v1' AND m = 'v2')", $cb->toSql());
    }

    /**
     * A placeholder inside a quoted literal of the template is data, not a
     * binding site.
     */
    public function testPlaceholderInsideLiteralIsLeftAlone(): void
    {
        $cb = new Condition(new TestSqliteDatabase());
        $cb->where("a = 'lit?eral' AND b = ?", ['v']);

        $this->assertSame("(a = 'lit?eral' AND b = 'v')", $cb->toSql());
    }

    /**
     * A `:name` inside a substituted value must not be re-substituted either.
     */
    public function testNamedPlaceholderInsideValueIsNotReplaced(): void
    {
        $cb = new Condition(new TestSqliteDatabase());
        $cb->where('a = ? AND b = ?', ['x:v', 'y']);

        $this->assertSame("(a = 'x:v' AND b = 'y')", $cb->toSql());
    }

    /**
     * Values containing SQL metacharacters must be quoted by the driver.
     */
    public function testQuoteInValueIsDoubled(): void
    {
        $cb = new Condition(new TestSqliteDatabase());
        $cb->where('a = ?', ["x' OR '1'='1"]);

        $this->assertSame("(a = 'x'' OR ''1''=''1')", $cb->toSql());
    }

    /* --------------------------------------------------- Sql::pgArray() */

    /**
     * A single quote in an array element must not terminate the array
     * literal.
     */
    public function testPgArrayEscapesSingleQuotes(): void
    {
        $ser = fn($v) => "'" . str_replace("'", "''", (string) $v) . "'";

        $sql = Sql::pgArray(["it's"])->toSql('pgsql', $ser);

        $this->assertSame("'{\"it''s\"}'", $sql);
    }

    public function testPgArrayCannotBreakOutOfLiteral(): void
    {
        $ser = fn($v) => "'" . str_replace("'", "''", (string) $v) . "'";

        $sql = Sql::pgArray(["x'); DROP TABLE users; --"])->toSql('pgsql', $ser);

        $this->assertSame("'{\"x''); DROP TABLE users; --\"}'", $sql);

        // Only the two outer quotes are unescaped.
        $this->assertStringStartsWith("'{", $sql);
        $this->assertStringEndsWith("}'", $sql);
    }

    public function testPgArrayPlainValuesUnchanged(): void
    {
        $ser = fn($v) => "'" . str_replace("'", "''", (string) $v) . "'";

        $this->assertSame("'{\"a\",\"b\"}'", Sql::pgArray(['a', 'b'])->toSql('pgsql', $ser));
        $this->assertSame("'{1,2}'", Sql::pgArray([1, 2])->toSql('pgsql', $ser));
    }

    public function testPgArrayDoubleQuotesStillEscaped(): void
    {
        $ser = fn($v) => "'" . str_replace("'", "''", (string) $v) . "'";

        $this->assertSame(
            "'{\"a\\\"b\"}'",
            Sql::pgArray(['a"b'])->toSql('pgsql', $ser)
        );
    }
}
