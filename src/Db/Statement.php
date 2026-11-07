<?php

namespace Azera\Db;

use Azera\Db\Event\StatementExecuted;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A prepared statement bound to a SQL connection.
 *
 * Unlike the legacy Database::prepare()/execute() pair, a PreparedStatement
 * owns its PDOStatement, so any number of statements can be prepared and
 * executed concurrently without clobbering a single shared slot.
 */
class Statement
{
    protected Database $db;

    protected PDOStatement $statement;

    protected string $sql;

    /** @var bool Whether this statement has already been offered back to the pool. */
    protected bool $released = false;

    /**
     * Create a new PreparedStatement wrapping an already-prepared PDO statement.
     *
     * @param Database     $db        SQL connection used to prepare the statement.
     * @param PDOStatement $statement The prepared PDO statement.
     * @param string       $sql       The original SQL string (used for error reporting).
     */
    public function __construct(Database $db, PDOStatement $statement, string $sql)
    {
        $this->db        = $db;
        $this->statement = $statement;
        $this->sql       = $sql;
    }

    /**
     * Execute the statement with the given bound parameters.
     *
     * Deadlocks and connection-loss errors are retried through the connection's
     * auto-reconnect logic, mirroring Database::query().
     *
     * @param array $params Optional parameters to bind for this execution.
     * @return bool|Statement Returns $this for chaining when the statement returns rows, true otherwise.
     * @throws \Exception On database errors.
     */
    public function execute(array $params = []): bool|Statement
    {
        $start = microtime(true);
        while (true) {
            try {
                $this->statement->closeCursor();
                $ok = $this->statement->execute($params);

                if ($ok === false) {
                    $info = $this->statement->errorInfo();
                    $ex   = new PDOException($info[2] ?? 'Unknown error');
                    $ex->errorInfo = $info;
                    throw $ex;
                }

                return ($this->statement->columnCount() > 0) ? $this : true;
            } catch (PDOException $exception) {
                $this->db->processPdoException($exception, 'execute', $this->sql, $params);
            } finally {
                $this->db->events()->dispatch(new StatementExecuted(
                    $this->db,
                    $params,
                    (microtime(true) - $start) * 1000.0
                ));
            }
        }
    }

    /**
     * Return the number of rows affected by the last execution.
     * @return int
     */
    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    /**
     * Return the number of columns in the result set.
     * @return int
     */
    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    /**
     * Return the underlying PDO statement instance.
     * @return PDOStatement
     */
    public function getStatement(): PDOStatement
    {
        return $this->statement;
    }

    /**
     * Return the original SQL string this statement was prepared from.
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Close the cursor on the underlying PDO statement.
     *
     * This releases any locks the statement may still hold.  It is especially
     * important for write statements that only partially consumed their result
     * set: on SQLite in WAL mode, an open cursor on a write statement keeps the
     * write lock held on its connection, blocking writes from other
     * connections.
     *
     * @return void
     */
    public function closeCursor(): void
    {
        $this->statement->closeCursor();
    }

    /**
     * Ensure the underlying statement cursor is released when this statement
     * goes out of scope, so that any Database locks it holds are freed.
     */
    public function __destruct()
    {
        $this->closeCursor();

        if (!$this->released) {
            $this->released = true;
            $this->db->releaseStatement($this->sql, $this->statement);
        }
    }
}
