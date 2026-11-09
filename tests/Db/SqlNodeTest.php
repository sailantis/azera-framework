<?php
namespace Azera\Tests\Db;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/TestDatabase.php';

use Azera\Db\Sql;
use PHPUnit\Framework\TestCase;

class SqlNodeTest extends TestCase
{
    // Helper: serialize a Sql value via Condition and return the RHS expression
    private function serializeViaCondition($value): string
    {
        $db = new TestPgDatabase();
        $cb = new \Azera\Db\Condition($db);
        $cb->where('x = :v', ['v' => $value]);
        return $cb->toSql();
    }

    public function testPgArray1D(): void
    {
        $array = ['a', 'b', 'c'];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{\"a\",\"b\",\"c\"}')", $sql);
    }

    public function testPgArrayConditionBuilder(): void
    {
        $db = new TestPgDatabase();
        $cb = new \Azera\Db\Condition($db);

        $cb->where('col = :v', ['v' => Sql::pgArray(['a', 'b', 'c'])]);
        $this->assertEquals("(col = '{\"a\",\"b\",\"c\"}')", $cb->toSql());

        $cb2 = new \Azera\Db\Condition($db);
        $cb2->where('col = :v', ['v' => Sql::pgArray([['a', 'b'], ['c', 'd']])]);
        $this->assertStringContainsString('{"a","b"', $cb2->toSql());
    }

    public function testPgArray2D(): void
    {
        $array = [['a', 'b'], ['c', 'd']];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{{\"a\",\"b\"},{\"c\",\"d\"}}')", $sql);
    }

    public function testPgArray3D(): void
    {
        $array = [[['a', 'b'], ['c', 'd']], [['e', 'f'], ['g', 'h']]];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{{{\"a\",\"b\"},{\"c\",\"d\"}},{{\"e\",\"f\"},{\"g\",\"h\"}}}')", $sql);
    }

    public function testPgArrayMixedTypes(): void
    {
        $array = ['string', 42, null, true, false];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{\"string\",42,NULL,TRUE,FALSE}')", $sql);
    }

    public function testPgArrayEmpty(): void
    {
        $array = [];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{}')", $sql);
    }

    public function testPgArrayEmptyNested(): void
    {
        $array = [[], ['a']];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{{},{\"a\"}}')", $sql);
    }

    public function testPgArrayComplexNesting(): void
    {
        $array = [
            'simple',
            ['level1', ['level2', ['level3']]],
        ];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{\"simple\",{\"level1\",{\"level2\",{\"level3\"}}}}')", $sql);
    }

    public function testPgArrayNumericKeys(): void
    {
        // Test that numeric keys are ignored (PHP arrays maintain insertion order)
        $array = [2 => 'a', 0 => 'b', 1 => 'c'];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{\"a\",\"b\",\"c\"}')", $sql);
    }

    public function testPgArrayStringKeys(): void
    {
        // PostgreSQL arrays don't support associative arrays, so string keys should be ignored
        $array = ['key1' => 'a', 'key2' => 'b'];
        $sql = $this->serializeViaCondition(Sql::pgArray($array));
        $this->assertEquals("(x = '{\"a\",\"b\"}')", $sql);
    }

    public function testParamNodeEmitsNamedBindingReference(): void
    {
        $db = new TestPgDatabase();
        $cb = new \Azera\Db\Condition($db);

        $cb->where('col = :v', ['v' => Sql::param('id')]);
        $sql = $cb->toSql();

        // Sql::param('id') should emit :id directly, referencing a manual binding by name
        $this->assertStringContainsString('(col = :id)', $sql);
        $this->assertStringNotContainsString('__p', $sql);
    }

    public function testJsonNodeSerialization(): void
    {
        $db = new TestPgDatabase();
        $cb = new \Azera\Db\Condition($db);

        $cb->where('data = :v', ['v' => Sql::json(['a' => 1, 'b' => 'x'])]);
        $this->assertEquals("(data = '{\"a\":1,\"b\":\"x\"}')", $cb->toSql());
    }

    public function testFunctionWithParamAndLiteral(): void
    {
        $db = new TestPgDatabase();
        $cb = new \Azera\Db\Condition($db);

        $fn = Sql::func('concat', ['pre_', Sql::param('id')]);
        $cb->where('col = :v', ['v' => $fn]);

        $sql = $cb->toSql();
        $this->assertStringContainsString("concat('pre_'", $sql);
        // Sql::param('id') inside a function should emit :id directly
        $this->assertStringContainsString(':id', $sql);
        $this->assertStringNotContainsString(':__p', $sql);
    }

    public function testCastDriverSpecificPg(): void
    {
        $sql = $this->serializeViaCondition(Sql::cast(Sql::column('title'), 'tsvector'));
        $this->assertEquals('(x = "title"::tsvector)', $sql);
    }

    // ========== Tests for new concat(), expr(), as(), and case() features ==========

    public function testConcatPostgreSQL(): void
    {
        $db = new TestPgDatabase();
        $concat = Sql::concat(Sql::column('first_name'), ' ', Sql::column('last_name'));
        $sql = $concat->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals('"first_name" || \' \' || "last_name"', $sql);
    }

    public function testConcatMySQL(): void
    {
        $db = new TestMysqlDatabase();
        $concat = Sql::concat(Sql::column('first_name'), ' ', Sql::column('last_name'));
        $sql = $concat->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals("CONCAT(`first_name`, ' ', `last_name`)", $sql);
    }

    public function testConcatSQLite(): void
    {
        $db = new TestSqliteDatabase();
        $concat = Sql::concat(Sql::column('first_name'), ' ', Sql::column('last_name'));
        $sql = $concat->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals('"first_name" || \' \' || "last_name"', $sql);
    }

    public function testConcatWithMultipleParts(): void
    {
        $db = new TestPgDatabase();
        $concat = Sql::concat(
            Sql::column('prefix'),
            '-',
            Sql::column('id'),
            '-',
            Sql::column('suffix')
        );
        $sql = $concat->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals('"prefix" || \'-\' || "id" || \'-\' || "suffix"', $sql);
    }

    public function testExprWithRawAndColumn(): void
    {
        $db = new TestPgDatabase();
        $expr = Sql::expr(
            Sql::raw('UPPER('),
            Sql::column('name'),
            Sql::raw(')')
        );
        $sql = $expr->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals('UPPER( "name" )', $sql);
    }

    public function testValueString(): void
    {
        $db = new TestPgDatabase();
        $value = Sql::value('hello');
        $sql = $value->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals("'hello'", $sql);
    }

    public function testValueInteger(): void
    {
        $db = new TestPgDatabase();
        $value = Sql::value(42);
        $sql = $value->toSql($db->getDriver(), fn($v) => is_int($v) ? (string) $v : $v);

        $this->assertEquals('42', $sql);
    }

    public function testFuncWithValue(): void
    {
        $db = new TestPgDatabase();
        // COALESCE(status, 'pending')
        $coalesce = Sql::func('COALESCE', [
            Sql::column('status'),
            Sql::value('pending')
        ]);
        $sql = $coalesce->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals("COALESCE(\"status\", 'pending')", $sql);
    }

    public function testAliasOnColumn(): void
    {
        $db = new TestPgDatabase();
        $aliased = Sql::column('customer_id')->as('id');
        $sql = $aliased->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals('"customer_id" AS "id"', $sql);
    }

    public function testAliasOnConcat(): void
    {
        $db = new TestPgDatabase();
        $aliased = Sql::concat(Sql::column('first_name'), ' ', Sql::column('last_name'))->as('full_name');
        $sql = $aliased->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals('"first_name" || \' \' || "last_name" AS "full_name"', $sql);
    }

    public function testAliasOnFunction(): void
    {
        $db = new TestPgDatabase();
        $aliased = Sql::func('COUNT', ['*'])->as('total');
        $sql = $aliased->toSql($db->getDriver(), fn($v) => $v);

        $this->assertEquals('COUNT(*) AS "total"', $sql);
    }

    public function testCaseSimple(): void
    {
        $db = new TestPgDatabase();
        $case = Sql::case()
            ->when(Sql::column('active'), 1)
            ->else(0)
            ->end();
        $sql = $case->toSql($db->getDriver(), fn($v) => is_int($v) ? $v : "'$v'");

        $this->assertEquals('CASE WHEN "active" THEN 1 ELSE 0 END', $sql);
    }

    public function testCaseWithMultipleWhen(): void
    {
        $db = new TestPgDatabase();
        $case = Sql::case()
            ->when(Sql::column('status'), 'active')
            ->when(Sql::column('pending'), 'pending')
            ->else('inactive')
            ->end();
        $sql = $case->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals("CASE WHEN \"status\" THEN 'active' WHEN \"pending\" THEN 'pending' ELSE 'inactive' END", $sql);
    }

    public function testCaseWithAlias(): void
    {
        $db = new TestPgDatabase();
        $case = Sql::case()
            ->when(Sql::column('active'), 'Yes')
            ->else('No')
            ->end()
            ->as('status_text');
        $sql = $case->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals("CASE WHEN \"active\" THEN 'Yes' ELSE 'No' END AS \"status_text\"", $sql);
    }

    public function testCaseWithRawSql(): void
    {
        $db = new TestPgDatabase();
        $case = Sql::case()
            ->when(Sql::column('active'), Sql::raw("'active'::text"))
            ->else(Sql::raw("''::text"))
            ->end();
        $sql = $case->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertEquals("CASE WHEN \"active\" THEN 'active'::text ELSE ''::text END", $sql);
    }

    public function testComplexNestedExpression(): void
    {
        $db = new TestPgDatabase();

        // Build: CASE WHEN active THEN CONCAT(first_name, ' ', last_name) ELSE 'N/A' END AS display_name
        $nameConcat = Sql::concat(Sql::column('first_name'), ' ', Sql::column('last_name'));
        $case = Sql::case()
            ->when(Sql::column('active'), $nameConcat)
            ->else('N/A')
            ->end()
            ->as('display_name');

        $sql = $case->toSql($db->getDriver(), fn($v) => is_string($v) ? "'$v'" : $v);

        $this->assertStringContainsString('CASE WHEN "active" THEN', $sql);
        $this->assertStringContainsString('"first_name" || \' \' || "last_name"', $sql);
        $this->assertStringContainsString("ELSE 'N/A' END", $sql);
        $this->assertStringContainsString('AS "display_name"', $sql);
    }

    public function testColumnWithDotNotationSetsFlag(): void
    {
        // Test that Model.column notation sets mustResolve flag
        // (Even though resolution is not yet fully implemented, the flag should be set)
        $col = Sql::column('Customer.name');

        // Use reflection to check the flag
        $reflection = new \ReflectionClass($col);
        $property = $reflection->getProperty('mustResolve');

        $this->assertTrue($property->getValue($col));
    }

    public function testColumnWithoutDotNotationNoFlag(): void
    {
        $col = Sql::column('name');

        $reflection = new \ReflectionClass($col);
        $property = $reflection->getProperty('mustResolve');

        $this->assertFalse($property->getValue($col));
    }

    public function testModelColumnResolutionWithProtectIdentifierCallback(): void
    {
        // Test that Model.column is properly resolved when protectIdentifier callback is provided
        $db = new TestPgDatabase();

        // Create a mock protectIdentifier that simulates Model -> table resolution
        $protectIdentifier = function (string $identifier): string {
            // Simulate Model.column resolution: Customer.name -> customer.name
            if (strpos($identifier, '.') !== false) {
                $parts = explode('.', $identifier, 2);
                $table = strtolower($parts[0]); // Simple lowercase for test
                return '"' . $table . '"."' . $parts[1] . '"';
            }
            return '"' . $identifier . '"';
        };

        $col = Sql::column('Customer.name');
        $sql = $col->toSql(
            $db->getDriver(),
            fn($v) => is_string($v) ? "'$v'" : $v,
            $protectIdentifier
        );

        // Should resolve Customer -> customer and quote properly
        $this->assertEquals('"customer"."name"', $sql);
    }

    public function testConcatWithModelColumnResolution(): void
    {
        // Test that concat() works with Model.column notation
        $db = new TestPgDatabase();

        $protectIdentifier = function (string $identifier): string {
            if (strpos($identifier, '.') !== false) {
                $parts = explode('.', $identifier, 2);
                $table = strtolower($parts[0]);
                return '"' . $table . '"."' . $parts[1] . '"';
            }
            return '"' . $identifier . '"';
        };

        $concat = Sql::concat(
            Sql::column('Customer.first_name'),
            ' ',
            Sql::column('Customer.last_name')
        );

        $sql = $concat->toSql(
            $db->getDriver(),
            fn($v) => is_string($v) ? "'$v'" : $v,
            $protectIdentifier
        );

        $this->assertEquals('"customer"."first_name" || \' \' || "customer"."last_name"', $sql);
    }

    private function serializeScalar($value, bool $forIdentifier = false)
    {
        if ($forIdentifier) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_null($value)) {
            return 'NULL';
        }
        throw new \InvalidArgumentException("Unsupported scalar type: " . get_debug_type($value));
    }
}
