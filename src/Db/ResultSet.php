<?php

namespace Azera\Db;

use PDO;
use PDOStatement;

/**
 * Forward-only cursor over an executed statement. Provides various fetch methods to retrieve rows as associative arrays, objects, or single column values.
 */
class ResultSet implements \Iterator, \Countable
{
	protected Database $db;
	protected PDOStatement $statement;
	protected ?string $sqlStatement;
	protected ?array $boundParams;
	protected int $fetchMode;

	// Iterator state
	protected mixed $currentRow = null;
	protected int $position = 0;
	protected bool $initialized = false;

	/** @var bool Whether this result set stems from a read-only (SELECT) statement. */
	protected bool $isReadQuery;

	/**
	 * Whether this statement has already been offered back to the connection's pool.
	 *
	 * Only {@see ResultSet::__destruct()} releases, and a destructor runs once, so this
	 * is belt-and-braces rather than load-bearing: it keeps a subclass or a second
	 * destruction path from offering the same statement twice, which would leave one
	 * slot holding a statement that another reader believes it owns.
	 */
	protected bool $released = false;

	/**
	 * Create a new ResultSet wrapping a PDO statement result.
	 *
	 * @param Database        $connection   SQL connection used to execute the query.
	 * @param PDOStatement    $statement    The executed PDO statement.
	 * @param string|null     $sqlStatement The original SQL string (used by reexecute()).
	 * @param array|null      $boundParams  Bound parameters (used by reexecute()).
	 * @param bool            $isReadQuery  Whether the statement is a read-only SELECT. Defaults to true.
	 *                          Set to false for write statements (e.g. INSERT/UPDATE/DELETE ... RETURNING),
	 *                          which cannot be safely re-executed via {@see refresh()}.
	 */
	public function __construct(
		Database $connection,
		PDOStatement $statement,
		?string $sqlStatement = null,
		?array $boundParams = null,
		bool $isReadQuery = true
	) {
		$this->db           = $connection;
		$this->statement    = $statement;
		$this->sqlStatement = $sqlStatement;
		$this->boundParams  = $boundParams;
		$this->isReadQuery  = $isReadQuery;

		$this->fetchMode = $connection->getDefaultFetchMode();
	}

	/**
	 * Fetch next row as object or array depending on fetch mode.
	 * @return object|array|false The next row as an object or array depending on the fetch mode, or false if there are no more rows.
	 */
	public function fetch(): object|array|false
	{
		$this->position++;
		return $this->statement->fetch($this->fetchMode);
	}

	/**
	 * Fetch next row as associative array.
	 * @return array|false The next row as an associative array, or false if there are no more rows.
	 */
	public function fetchAssoc(): array|false
	{
		$this->position++;
		return $this->statement->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Fetch next row as object.
	 * @return object|false The next row as an object, or false if there are no more rows.
	 */
	public function fetchObject(): object|false
	{
		$this->position++;
		return $this->statement->fetch(PDO::FETCH_OBJ);
	}

	/**
	 * Fetch next row as a single column value.
	 * @param int $column Zero-based column index to fetch, or 0 for the first column.
	 * @return mixed The value of the specified column in the next row, or false if there are no more rows.
	 */
	public function fetchColumn(int $column = 0): mixed
	{
		$this->position++;
		return $this->statement->fetchColumn($column);
	}

	/**
	 * Return all rows as associative array.
	 * @return array<int, array<string, mixed>> An array of all remaining rows, each as an associative array.
	 */
	public function fetchAllAssoc(): array
	{
		$result = $this->statement->fetchAll(PDO::FETCH_ASSOC);
		$this->position += \count($result);
		return $result;
	}

	/**
	 * Return all rows as object.
	 * @return array<int, object> An array of all remaining rows, each as an object.
	 */
	public function fetchAllObject(): array
	{
		$result = $this->statement->fetchAll(PDO::FETCH_OBJ);
		$this->position += \count($result);
		return $result;
	}

	/**
	 * Fetch all values from a single column.
	 * @param int $column Zero-based column index to fetch, or 0 for the first column.
	 * @return array The values of the specified column in all remaining rows.
	 */
	public function fetchAllColumn(int $column = 0): array
	{
		$result = $this->statement->fetchAll(PDO::FETCH_COLUMN, $column);
		$this->position += \count($result);
		return $result;
	}

	/**
	 * Fetch all rows as objects or arrays depending on fetch mode.
	 * @param int $fetchMode PDO::FETCH_* constant or 0 for default fetch mode
	 * @return array An array of all remaining rows, each as an object or array depending on the fetch mode.
	 */
	public function fetchAll(int $fetchMode = PDO::FETCH_DEFAULT): array
	{
		$result = $this->statement->fetchAll($fetchMode ?: $this->fetchMode);
		$this->position += \count($result);
		return $result;
	}

	/**
	 * Set the default fetch mode for this result set.
	 * @param int $fetchMode One of the PDO::FETCH_* constants
	 */
	public function setFetchMode(int $fetchMode): void
	{
		$this->fetchMode = $fetchMode;
	}

	/**
	 * Return the SQL statement that was executed to produce this result set, if available.
	 * @return string|null The SQL statement string, or null if not available.
	 */
	public function getSql(): ?string
	{
		return $this->sqlStatement;
	}

	/**
	 * Return the variables that were bound to the SQL statement, if available.
	 * @return array|null The variables that were bound to the SQL statement, or null if not available.
	 */
	public function getBindings(): ?array
	{
		return $this->boundParams;
	}

	/**
	 * Convert the result set to a plain array of rows.
	 *
	 * Makes the result set compatible with template engines and serializers
	 * that expect array-like data (e.g. Clarity's castToArray).
	 *
	 * @return array<int, array<string, mixed>> All remaining rows as arrays.
	 */
	public function toArray(): array
	{
		return $this->fetchAllAssoc();
	}

	/**
	 * Execute the query again to repopulate the result set.
	 *
	 * Only read-only (SELECT) result sets can be safely refreshed. Refreshing a
	 * write statement (e.g. INSERT/UPDATE/DELETE ... RETURNING) would re-execute
	 * the write, so it is rejected.
	 *
	 * @throws Exception If this result set does not originate from a SELECT statement.
	 * @return void
	 */
	public function refresh(): void
	{
		if (!$this->isReadQuery) {
			throw new Exception('Cannot refresh: statement is not a read-only SELECT result set.');
		}
		$this->closeCursor();
		$stmt = $this->db->query(
			$this->sqlStatement,
			$this->boundParams
		);
		$this->statement   = $stmt;
		$this->currentRow  = null;
		$this->position    = 0;
		$this->initialized = false;
		// The replacement statement is a different object and has to be eligible for
		// release on its own account, or a refreshed set would never pool again.
		$this->released    = false;
	}

	// Iterator methods

	/**
	 * Rewind the iterator to the first row.
	 *
	 * The underlying PDOStatement cursor is forward-only, so we cannot
	 * actually rewind once iteration has started.  However, on the first
	 * call (before any rows have been fetched) we lazily fetch the first
	 * row so that valid() returns true and PHP's foreach can begin.
	 */
	public function rewind(): void
	{
		if (!$this->initialized) {
			$this->currentRow  = $this->fetch();
			$this->initialized = true;
		}
	}

	/** Return the current row (fetched lazily on first access). */
	public function current(): mixed
	{
		if (!$this->initialized) {
			$this->currentRow  = $this->fetch();
			$this->initialized = true;
		}
		return $this->currentRow;
	}

	/** Return the zero-based position of the current row within this traversal. */
	public function key(): int
	{
		return $this->position;
	}

	/** Advance to the next row. */
	public function next(): void
	{
		$this->currentRow = $this->fetch();
		$this->position++;
	}

	/** Return true while the current row is not false/null (i.e., while rows remain). */
	public function valid(): bool
	{
		return $this->currentRow !== false && $this->currentRow !== null;
	}

	/**
	 * Return the number of rows affected/returned by the underlying statement.
	 * @return int Row count as reported by PDOStatement::rowCount().
	 */
	public function count(): int
	{
		return $this->statement->rowCount();
	}

	/**
	 * Close the cursor on the underlying PDO statement.
	 *
	 * This releases any locks the statement may still hold.  It is especially
	 * important for statements that only partially consumed their result set
	 * (e.g. {@see Model::__performWrite()} fetching a single RETURNING row): on
	 * SQLite in WAL mode, an open cursor on a write statement keeps the write
	 * lock held on its connection, blocking writes from other connections.
	 *
	 * **Deliberately does not hand the statement back to the pool.** `closeCursor()`
	 * means "I have finished reading", not "this object is gone": the `ResultSet` is
	 * still alive and still fetchable afterwards, and PHP's contract there is that
	 * `fetch()` returns `false`. Returning the statement at this point broke that
	 * contract — a second query would take the same `PDOStatement`, its `execute()`
	 * would rewind the cursor, and the first reader's next `fetch()` would silently
	 * answer with the *other* query's rows instead of `false`. The pool is therefore
	 * fed from {@see ResultSet::__destruct()} only, where nothing can read any more.
	 *
	 * @return void
	 */
	public function closeCursor(): void
	{
		$this->statement->closeCursor();
	}

	/**
	 * Return the cursor to the connection's statement pool, once, at the end of life.
	 *
	 * This is the only safe release point. A statement offered from `closeCursor()`
	 * could be re-executed by a later query while this object was still reachable and
	 * still answering `fetch()` — see {@see ResultSet::closeCursor()} for the sequence
	 * that produced another query's rows. At destruction there is no reachable
	 * `ResultSet` left, so reuse cannot be observed.
	 */
	public function __destruct()
	{
		$this->statement->closeCursor();

		if (!$this->released) {
			$this->released = true;
			$this->db->releaseStatement($this->sqlStatement, $this->statement);
		}
	}

}
