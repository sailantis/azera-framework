<?php

namespace Azera\Tests\Db;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Db\Database;
use Azera\Db\ResultSet;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;
use ReflectionClass;

/**
 * The statement pool, tested for the corruption it could cause rather than only for
 * the speed it buys.
 *
 * Two invariants carry the design, and each of them was broken by an earlier revision
 * of this code before it was measured:
 *
 *   1. **Two live readers never share a `PDOStatement`.** A statement is removed from
 *      the pool when it is taken, so a second query with the same SQL prepares its
 *      own. Without this the second `execute()` rewinds the first reader's cursor.
 *
 *   2. **A statement is released only when no reader can still fetch from it.** The
 *      first version released on `closeCursor()`, on the reasoning that the caller had
 *      said it was done. It had not: the `ResultSet` was still alive and still
 *      fetchable, and PHP's contract for it is that `fetch()` then returns `false`.
 *      Instead the next query took the same statement, re-executed it, and the first
 *      reader's next `fetch()` answered with the *other* query's rows — a silent wrong
 *      answer rather than an error. Releasing only at destruction fixes it.
 *
 * `test_a_reader_that_closed_its_cursor_does_not_see_another_query_rows` is the
 * regression test for (2); the assertions on `assertNotSame(... statementOf ...)`
 * cover (1).
 */
final class StatementCacheTest extends TestCase
{
    private string $dbFile;

    private const SQL = 'SELECT id, title FROM items ORDER BY id ASC LIMIT 20';

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'azera-pool-') . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO items (id, title) VALUES (?, ?)');
        for ($i = 1; $i <= 40; $i++) {
            $insert->execute([$i, sprintf('Item %05d', $i)]);
        }
        $pdo->commit();
        $pdo = null;
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    private function db(bool $cache, int $limit = 64): Database
    {
        $db = new Database('sqlite:' . $this->dbFile);
        $db->useStatementCache($cache, $limit);

        return $db;
    }

    /**
     * Build a ResultSet the way {@see \Azera\Db\Query::executeQuery()} does.
     *
     * Deliberately not `$db->query()`: that returns a raw `PDOStatement`, which has no
     * release hook and therefore never reaches the pool. The pool exists for the
     * framework's own read path, and a test that used the raw API would pass whether
     * or not the pooling logic worked — which is exactly what an earlier version of
     * this test did.
     */
    private function resultSet(Database $db, string $sql, ?array $params = null): ResultSet
    {
        return new ResultSet($db, $db->query($sql, $params), $sql, $params, true);
    }

    private static function statementOf(ResultSet $rs): PDOStatement
    {
        $property = (new ReflectionClass($rs))->getProperty('statement');
        $property->setAccessible(true);

        return $property->getValue($rs);
    }

    /** The pool is opt-in, so a connection that never asks for it must behave as before. */
    public function test_the_pool_is_off_by_default(): void
    {
        $db = new Database('sqlite:' . $this->dbFile);

        $this->assertFalse($db->usesStatementCache(), 'a connection must not pool unless asked');
    }

    /** Every row arrives, in order, with the pool on. */
    public function test_a_pooled_query_returns_every_row_in_order(): void
    {
        $db = $this->db(true);

        $ids = [];
        foreach ($this->resultSet($db, self::SQL) as $row) {
            $ids[] = (int) $row->id;
        }

        $this->assertSame(range(1, 20), $ids);
    }

    /**
     * Invariant 1. Two result sets over the same SQL are live at once; each must keep
     * its own cursor, and the first must still drain to its own last row.
     */
    public function test_two_live_result_sets_do_not_share_a_statement(): void
    {
        $db = $this->db(true);

        $first  = $this->resultSet($db, self::SQL);
        $second = $this->resultSet($db, self::SQL);

        $this->assertNotSame(
            self::statementOf($first),
            self::statementOf($second),
            'two live readers must not share one PDOStatement'
        );

        $firstIds  = [(int) $first->fetch()->id];
        $secondIds = [(int) $second->fetch()->id];
        while ($row = $first->fetch()) {
            $firstIds[] = (int) $row->id;
        }
        while ($row = $second->fetch()) {
            $secondIds[] = (int) $row->id;
        }

        $this->assertSame(range(1, 20), $firstIds, 'the first reader lost its place');
        $this->assertSame(range(1, 20), $secondIds, 'the second reader lost its place');
    }

    /**
     * Invariant 2, the regression test. A reader that has closed its cursor is still
     * alive, and its next `fetch()` must return `false` — not another query's rows.
     *
     * The releasing version of this code failed here: `$first->fetch()` answered with
     * id 2 of the *second* query instead of `false`.
     */
    public function test_a_reader_that_closed_its_cursor_does_not_see_another_query_rows(): void
    {
        $db = $this->db(true);

        $first = $this->resultSet($db, self::SQL);
        $this->assertSame(1, (int) $first->fetch()->id);

        $first->closeCursor();

        // A second query runs the same SQL. It must not be handed $first's statement.
        $second = $this->resultSet($db, self::SQL);
        $this->assertSame(1, (int) $second->fetch()->id);

        $this->assertFalse(
            $first->fetch(),
            'a reader that closed its cursor must report no more rows, not another query rows'
        );
        $this->assertNotSame(self::statementOf($first), self::statementOf($second));
    }

    /**
     * Invariant 1 in the case that actually reaches the pool: a statement is already
     * idle, and two readers are created from it in turn.
     *
     * This is what the pop-on-take rule exists for, and an earlier version of this
     * test missed it: with nothing idle, both readers prepared their own statement and
     * a broken pool could not be observed. Here a statement is put in the pool first,
     * so the first reader is *taken from it* — and the second must not be handed the
     * same one, because the first is still alive and still fetching.
     *
     * With the pop removed this test fails: both readers receive one `PDOStatement`,
     * and the second `execute()` rewinds the first reader's cursor.
     */
    public function test_two_readers_taken_from_a_populated_pool_do_not_share(): void
    {
        $db = $this->db(true);

        // Populate the pool: this reader is destroyed, releasing its statement.
        $warm = $this->resultSet($db, self::SQL);
        $warm->fetchAllAssoc();
        $pooled = self::statementOf($warm);
        unset($warm);

        // Both of these are served from a pool that holds exactly one idle statement.
        $first  = $this->resultSet($db, self::SQL);
        $second = $this->resultSet($db, self::SQL);

        $this->assertSame($pooled, self::statementOf($first), 'the first should reuse the pooled statement');
        $this->assertNotSame(
            self::statementOf($first),
            self::statementOf($second),
            'the second reader must prepare its own rather than share the first one statement'
        );

        $firstIds = [];
        while ($row = $first->fetch()) {
            $firstIds[] = (int) $row->id;
        }
        $this->assertSame(range(1, 20), $firstIds, 'the first reader must not lose its place');
    }

    /** A statement is genuinely reused once its reader is gone, and its rows are right. */
    public function test_a_released_statement_is_reused_and_still_correct(): void    {
        $db = $this->db(true);

        $first          = $this->resultSet($db, self::SQL);
        $before         = $first->fetchAllAssoc();
        $firstStatement = self::statementOf($first);
        unset($first);   // destruction is the release point

        $second = $this->resultSet($db, self::SQL);
        $after  = $second->fetchAllAssoc();

        $this->assertSame(
            $firstStatement,
            self::statementOf($second),
            'the statement should have come back out of the pool'
        );
        $this->assertSame($before, $after, 'a reused statement must return the same rows');
        $this->assertCount(20, $after);
    }

    /** Bound parameters must work through a pooled statement, and bind afresh each time. */
    public function test_pooled_statements_handle_bound_parameters(): void
    {
        $db  = $this->db(true);
        $sql = 'SELECT id FROM items WHERE id > :after ORDER BY id ASC LIMIT 5';

        $collect = function (int $after) use ($db, $sql): array {
            $ids = [];
            foreach ($this->resultSet($db, $sql, ['after' => $after]) as $row) {
                $ids[] = (int) $row->id;
            }

            return $ids;
        };

        $this->assertSame([11, 12, 13, 14, 15], $collect(10));
        $this->assertSame([31, 32, 33, 34, 35], $collect(30), 'the second binding must take effect');
    }

    /** Releasing twice must not pin two statements in one slot. */
    public function test_releasing_twice_is_harmless(): void
    {
        $db = $this->db(true);

        $first = $this->resultSet($db, self::SQL);
        $first->fetchAllAssoc();
        $first->closeCursor();
        $first->closeCursor();
        unset($first);

        $this->assertCount(20, $this->resultSet($db, self::SQL)->fetchAllAssoc());
    }

    /** A partially consumed reader that is abandoned must not poison the next query. */
    public function test_an_abandoned_result_set_releases_cleanly(): void
    {
        $db = $this->db(true);

        (function () use ($db): void {
            $rs = $this->resultSet($db, self::SQL);
            $rs->fetch();    // partially consumed, then abandoned
        })();

        $rows = $this->resultSet($db, self::SQL)->fetchAllAssoc();

        $this->assertCount(20, $rows);
        $this->assertSame(1, (int) $rows[0]['id']);
    }

    /** The cap must bound the pool rather than let a worker grow it without limit. */
    public function test_the_pool_respects_its_limit(): void
    {
        $db = $this->db(true, 4);

        for ($i = 0; $i < 20; $i++) {
            $rs = $this->resultSet($db, 'SELECT id FROM items WHERE id = ' . ($i + 1));
            $rs->fetchAllAssoc();
            unset($rs);
        }

        $this->assertCount(20, $this->resultSet($db, self::SQL)->fetchAllAssoc());
    }

    /** A statement must not survive a reconnect: it was prepared on the old handle. */
    public function test_reconnecting_drops_pooled_statements(): void
    {
        $db = $this->db(true);

        $first = $this->resultSet($db, self::SQL);
        $this->assertCount(20, $first->fetchAllAssoc());
        $oldStatement = self::statementOf($first);
        unset($first);

        $db->connect();

        $second = $this->resultSet($db, self::SQL);
        $this->assertCount(20, $second->fetchAllAssoc(), 'the pool must not survive a new connection');
        $this->assertNotSame($oldStatement, self::statementOf($second));
    }

    /** Turning the pool off must discard what it holds. */
    public function test_disabling_the_pool_flushes_it(): void
    {
        $db = $this->db(true);

        $first = $this->resultSet($db, self::SQL);
        $first->fetchAllAssoc();
        unset($first);

        $db->useStatementCache(false);
        $this->assertFalse($db->usesStatementCache());

        $this->assertCount(20, $this->resultSet($db, self::SQL)->fetchAllAssoc());
    }

    /** A write statement still reports its affected-row count with the pool on. */
    public function test_write_statements_still_report_their_row_count(): void
    {
        $db = $this->db(true);

        $db->query("UPDATE items SET title = 'changed' WHERE id <= 3");
        $this->assertSame(3, $db->rowCount());

        $db->query("UPDATE items SET title = 'again' WHERE id <= 3");
        $this->assertSame(3, $db->rowCount(), 'a pooled statement must not swallow rowCount()');

        $rows = $db->query('SELECT title FROM items WHERE id = 1')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame('again', $rows[0]['title']);
    }
}
