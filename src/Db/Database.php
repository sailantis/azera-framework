<?php

namespace Azera\Db;

use Azera\AppContext;
use Azera\Orm\Model;
use Azera\Db\Event\DatabaseOperationFailed;
use Azera\Db\Event\QueryExecuted;
use Azera\Db\Event\ReconnectAborted;
use Azera\Db\Event\ReconnectAttempt;
use Azera\Db\Event\Reconnected;
use Azera\Db\Event\ReconnectFailed;
use Azera\Db\Event\StatementPrepared;
use Azera\Db\Event\TransactionCommitted;
use Azera\Db\Event\TransactionRolledBack;
use Azera\Db\Event\TransactionStarted;
use Azera\Db\Exceptions\TransactionLostException;
use PDO;
use PDOException;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

/**
 * Class Database
 */
class Database
{
	protected string $connectString;

	protected string $user;

	protected string $driverName;

	protected string $pass;

	protected array $options;

	protected PDO $pdo;

	protected PDOStatement $statement;

	protected int $transactionLevel = 0;

	protected string $quoteChar = '"';

	protected bool|array $autoReconnect = false;

	/** @var EventDispatcherInterface|null Cached event dispatcher (resolved lazily). */
	protected ?EventDispatcherInterface $eventDispatcher = null;

	/** @var int|null Cached default fetch mode (resolved once per connection). */
	protected ?int $defaultFetchMode = null;

	/**
	 * Idle prepared statements, keyed by SQL text.
	 *
	 * ## Why a one-slot pool rather than a statement cache
	 *
	 * A cache keyed by SQL hands the same `PDOStatement` to two successive queries.
	 * That is a data-corruption bug, not a slowdown: the second `execute()` rewinds
	 * the first caller's cursor, and a caller that had only partially consumed its
	 * rows silently reads someone else's.
	 *
	 * Refcounting the statement would fix that, but a simpler rule fixes it for free:
	 * **a slot holds at most one *idle* statement, and taking one removes it from the
	 * map.** A second query with the same SQL while the first is still alive finds the
	 * slot empty and prepares its own, so two live consumers can never share a cursor
	 * — no bookkeeping, no leak if a caller forgets to release, and the pool converges
	 * back to one idle statement per distinct SQL.
	 *
	 * ## When a statement may be offered back
	 *
	 * Only from {@see ResultSet::__destruct()}, never from `closeCursor()`. A
	 * `ResultSet` that has closed its cursor is still alive and still fetchable, and
	 * PHP's contract is that `fetch()` then returns `false`. Releasing at `closeCursor()`
	 * — which an earlier revision of this code did — let the next query re-execute the
	 * same statement, and the first reader's next `fetch()` answered with the *other*
	 * query's rows instead of `false`. Destruction is the only point where no reader can
	 * observe the reuse. Both rules have regression tests in
	 * `tests/Db/StatementCacheTest.php`, each checked to fail when its rule is removed.
	 *
	 * @var array<string, PDOStatement>
	 */
	protected array $idleStatements = [];

	/**
	 * How many idle statements this connection may keep.
	 *
	 * A request typically runs a handful of distinct statements, so the cap exists
	 * only to keep a long-lived worker from growing a map without bound when an
	 * application builds SQL from user input. When the cap is reached the pool is
	 * dropped rather than evicted one entry at a time: the statements it holds are
	 * about to be re-prepared on demand, which is the cost a cache miss already has.
	 */
	protected int $statementCacheLimit = 64;

	/**
	 * Whether to reuse prepared statements between calls.
	 *
	 * Off by default, and that default is deliberate. `ATTR_EMULATE_PREPARES` is a
	 * connection setting an application may rely on, and on an emulating driver
	 * (pdo_mysql's default) the client-side substitution and quote-escaping happen
	 * *at execute time*. Reusing the statement object is still correct there, but
	 * `pdo_mysql` also caches nothing for the named-placeholder shape, so the win is
	 * unproven off SQLite. A deployment enables it where it has measured the
	 * difference - see {@see Database::useStatementCache()}.
	 */
	protected bool $statementCacheEnabled = false;

	/**
	 * Create a new database connection using the provided DSN, credentials and options.
	 * @param string $dsn
	 * @param string $user
	 * @param string $pass
	 * @param array $options
	 * @throws Exception
	 */
	public function __construct(
		string $dsn,
		string $user = "",
		string $pass = "",
		array $options = []
	) {
		$this->connectString = $dsn;
		$this->user          = $user;
		$this->pass          = $pass;

		$this->options = $options + [
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		];

		// Extract driver name from DSN
		$driver = strstr($dsn, ':', true);
		if ($driver === false) {
			throw new Exception("Invalid DSN string: $dsn");
		}
		$this->driverName = strtolower($driver);

		if ($this->driverName === 'mysql') {
			// ANSI quotes requires extra query. For performance reasons we will stick with backticks for MySQL.
			$this->quoteChar = '`';
		}

		$this->connect();
	}

	/**
	 * Establish a new PDO connection using the current configuration
	 * @throws Exception
	 */
	public function connect()
	{
		$this->pdo = new PDO(
			$this->connectString,
			$this->user,
			$this->pass,
			$this->options
		);
		$this->transactionLevel = 0;
		// Statements belong to the handle that prepared them. Reusing one across a
		// reconnect would execute against a connection it was not compiled on, which
		// on a different server is a wrong-answer bug rather than an error.
		$this->flushStatementCache();
	}

	/**
	 * Resolve the event dispatcher from AppContext, cached on first call.
	 *
	 * Returns a NullEventDispatcher when no real dispatcher is registered,
	 * so dispatch() is always safe and cheap (single no-op method call).
	 */
	public function events(): EventDispatcherInterface
	{
		return $this->eventDispatcher ??= AppContext::instance()->events();
	}

	/**
	 * Configure automatic reconnection behavior with detailed options
	 * @param bool $enabled Enable or disable auto-reconnect
	 * @param int $maxAttempts Maximum number of retry attempts (0 for unlimited)
	 * @param float $retryDelay Initial delay between retries in seconds
	 * @param float $backoffMultiplier Multiplier for exponential backoff
	 * @param float $maxRetryDelay Maximum delay between retries in seconds
	 * @param bool $jitter Whether to add random jitter to retry delays
	 * @param callable|null $onReconnect Optional callback invoked on successful reconnect (receives attempt number and db instance)
	 * @return $this
	 */
	public function setAutoReconnect(
		bool $enabled = true,
		int $maxAttempts = 0,
		float $retryDelay = 1.0,
		float $backoffMultiplier = 2.0,
		float $maxRetryDelay = 30.0,
		bool $jitter = true,
		?callable $onReconnect = null
	): static {
		$this->autoReconnect = [
			'enabled'           => $enabled,
			'maxAttempts'       => $maxAttempts > 0 ? $maxAttempts : null,
			'retryDelay'        => $retryDelay,
			'backoffMultiplier' => $backoffMultiplier,
			'maxRetryDelay'     => $maxRetryDelay,
			'jitter'            => $jitter,
			'onReconnect'       => $onReconnect,
		];
		return $this;
	}

	/**
	 * Get auto-reconnect configuration
	 * @return bool|array
	 */
	public function getAutoReconnect(): bool|array
	{
		return $this->autoReconnect;
	}

	/**
	 * Execute a SQL statement with optional parameters and return the resulting statement or success status.
	 * @param string $statement SQL statement to execute
	 * @param array|null $params Optional parameters for prepared statements
	 * @return bool|PDOStatement
	 * @throws Exception
	 */
	public function query(string $statement, ?array $params = null): bool|PDOStatement
	{
		$start = microtime(true);
		while (true) {
			try {
				if (!empty($params)) {
					$stmt = $this->takeIdleStatement($statement);
					if ($stmt === null) {
						$stmt = $this->pdo->prepare($statement);
					}
					$stmt->execute($params);
				} else {
					$stmt = $this->takeIdleStatement($statement);
					if ($stmt === null) {
						$stmt = $this->pdo->query($statement);
					} elseif ($stmt->execute() === false) {
						throw new PDOException('statement could not be re-executed from the pool');
					}
				}
				if ($stmt === false) {
					$info = $this->pdo->errorInfo();
					$ex   = new PDOException($info[2] ?? 'Unknown error');
					$ex->errorInfo = $info;
					throw $ex;
				}
				$this->statement = $stmt;
				return ($stmt->columnCount() > 0) ? $stmt : true;
			} catch (PDOException $exception) {
				$this->processPdoException($exception, 'query', $statement, $params);
			} finally {
				$this->events()->dispatch(new QueryExecuted(
					$this,
					$statement,
					$params,
					(microtime(true) - $start) * 1000.0
				));
			}
		}
	}

	/**
	 * Enable or disable reuse of prepared statements between calls.
	 *
	 * Enable it where the win has been measured. On SQLite it is worth about 3.8 µs
	 * of a 12.5 µs query, because `PDO::query()` re-parses and re-plans the SQL text
	 * on every call (`sqlite3_prepare_v2`), while a reused statement only re-runs
	 * the compiled program. The correctness rule is independent of the driver: see
	 * {@see Database::$idleStatements} for why taking a statement removes it from
	 * the pool, which is what keeps two live `ResultSet`s from sharing a cursor.
	 *
	 * @param bool $enabled Whether to reuse statements.
	 * @param int  $limit   Maximum idle statements held per connection.
	 * @return $this
	 */
	public function useStatementCache(bool $enabled = true, int $limit = 64): static
	{
		$this->statementCacheEnabled = $enabled;
		$this->statementCacheLimit   = max(1, $limit);
		if (!$enabled) {
			$this->flushStatementCache();
		}

		return $this;
	}

	/**
	 * Whether statements are reused between calls. See {@see Database::useStatementCache()}.
	 */
	public function usesStatementCache(): bool
	{
		return $this->statementCacheEnabled;
	}

	/**
	 * Take a pooled statement for this SQL, or null when the pool has none idle.
	 *
	 * Removal is the safety property, not an optimisation: see
	 * {@see Database::$idleStatements}.
	 */
	protected function takeIdleStatement(string $statement): ?PDOStatement
	{
		if (!$this->statementCacheEnabled) {
			return null;
		}

		$stmt = $this->idleStatements[$statement] ?? null;
		if ($stmt === null) {
			return null;
		}

		unset($this->idleStatements[$statement]);

		return $stmt;
	}

	/**
	 * Offer an exhausted statement back to the pool.
	 *
	 * Called through {@see Database::releaseStatement()}, which a `ResultSet` reaches
	 * on `closeCursor()` and on destruction. A returned statement is always
	 * `closeCursor()`ed first: on SQLite in WAL mode an open cursor holds a read
	 * snapshot, and handing one back to the pool would keep that snapshot alive on
	 * the connection, blocking writers from other connections.
	 *
	 * Releasing is best-effort and idempotent - a slot that is already occupied keeps
	 * what it has rather than replacing it, so a double release cannot pin two
	 * statements in one slot and lose one of them.
	 */
	public function releaseStatement(string $statement, PDOStatement $stmt): void
	{
		if (!$this->statementCacheEnabled) {
			return;
		}

		if (count($this->idleStatements) >= $this->statementCacheLimit) {
			$this->flushStatementCache();
		}

		if (isset($this->idleStatements[$statement])) {
			return;
		}

		try {
			$stmt->closeCursor();
		} catch (\Throwable) {
			// A statement that cannot be reset is not worth pooling; dropping it is
			// always safe because the pool is an optimisation.
			return;
		}

		$this->idleStatements[$statement] = $stmt;
	}

	/**
	 * Drop every pooled statement.
	 *
	 * Called when the connection is replaced (see {@see Database::connect()}), because
	 * statements prepared on the previous handle cannot be executed on the new one.
	 */
	public function flushStatementCache(): void
	{
		$this->idleStatements = [];
	}

	/**
	 * Prepare a SQL statement and return a Statement wrapper.
	 *
	 * Each call returns an independent Statement that owns its PDO
	 * statement, so any number of statements can be prepared and executed
	 * concurrently without clobbering a single shared slot.
	 *
	 * @param string $statement SQL statement to prepare
	 * @return Statement
	 * @throws \Exception
	 */
	public function prepare(string $statement): Statement
	{
		while (true) {
			try {
				$stmt = $this->pdo->prepare($statement);
				if ($stmt === false) {
					$info = $this->pdo->errorInfo();
					$ex   = new PDOException($info[2] ?? 'Unknown error');
					$ex->errorInfo = $info;
					throw $ex;
				}
				return new Statement($this, $stmt, $statement);
			} catch (PDOException $exception) {
				$this->processPdoException($exception, 'prepare', $statement);
			} finally {
				$this->events()->dispatch(new StatementPrepared($this, $statement));
			}
		}
	}

	/**
	 * @param PDOException $exception
	 * @param string $operation The database operation that failed.
	 * @param string|null $sql The SQL statement that failed, if known.
	 * @param array|null $params Bound parameters for the failed statement, if known.
	 * @throws Exception
	 */
	public function processPdoException(PDOException $exception, string $operation, ?string $sql = null, ?array $params = null)
	{
		$this->events()->dispatch(new DatabaseOperationFailed($this, $exception, $operation, $sql, $params));
		$inTransaction = !empty($this->transactionLevel);
		switch ($exception->errorInfo[1]) {
			case 1213:

			case '40P01':

			case 40001:
				// Deadlock or timeout with automatic rollback occurred.
				if ($inTransaction) {
					throw new TransactionLostException(
						"Deadlock found when trying to get lock; try restarting transaction",
						40001,
						$exception
					);
				}
				// Re-run last command
				return;
			case 2006:

			case 2013:

			case 8001:
			case 8004:
			case 8006:
				// PGSQL: 8006 Connection Exception
				$this->handleReconnect($exception);
				// Re-run last command
				return;
		}
		throw $exception;
	}

	protected function handleReconnect(?\Exception $exception = null)
	{
		// Get auto-reconnect configuration
		$config = is_array($this->autoReconnect)
			? $this->autoReconnect
			: ['enabled' => $this->autoReconnect];

		// Check if auto-reconnect is enabled
		if (!($config['enabled'] ?? false)) {
			return;
		}

		$inTransaction = !empty($this->transactionLevel);

		// Get configuration with defaults
		$maxAttempts       = $config['maxAttempts'] ?? null; // null = unlimited
		$retryDelay        = $config['retryDelay'] ?? 1.0;
		$backoffMultiplier = $config['backoffMultiplier'] ?? 2.0;
		$maxRetryDelay     = $config['maxRetryDelay'] ?? 30.0;
		$useJitter         = $config['jitter'] ?? true;
		$reconnectCallback = $config['onReconnect'] ?? null;

		$attempt      = 1;
		$currentDelay = $retryDelay;

		while ($maxAttempts === null || $attempt <= $maxAttempts) {
			$this->events()->dispatch(
				new ReconnectAttempt($this, $attempt, $currentDelay, $exception)
			);

			// Sleep with optional jitter
			if ($attempt > 1) {
				// Don't sleep on first attempt
				$sleepTime = $currentDelay;
				if ($useJitter) {
					// Add Â±25% jitter to prevent thundering herd
					$jitterRange = $sleepTime * 0.25;
					$sleepTime += (mt_rand() / mt_getrandmax() * $jitterRange * 2) - $jitterRange;
				}

				if ($sleepTime >= 1) {
					sleep((int) $sleepTime);
					$remaining = $sleepTime - (int) $sleepTime;
					if ($remaining > 0) {
						usleep((int) ($remaining * 1000000));
					}
				} else {
					usleep((int) ($sleepTime * 1000000));
				}
			}

			try {
				$this->connect();

				// Success! Invoke callback if configured
				if ($reconnectCallback !== null) {
					try {
						($reconnectCallback)($attempt, $this);
					} catch (\Exception $callbackEx) {
						$this->events()->dispatch(
							new ReconnectFailed($this, $callbackEx, $attempt)
						);
					}
				}

				$this->events()->dispatch(
					new Reconnected($this, $attempt)
				);

				// Re-run last command
				return;

			} catch (\Exception $reconnectEx) {
				$this->events()->dispatch(
					new ReconnectFailed($this, $reconnectEx, $attempt)
				);

				// Calculate next delay with exponential backoff
				$currentDelay = min($currentDelay * $backoffMultiplier, $maxRetryDelay);
				$attempt++;
			}
		}

		// All retry attempts exhausted
		$this->events()->dispatch(
			new ReconnectAborted($this, $attempt)
		);
	}

	/**
	 * Fetch a single row from the database as object, associative array, or numeric array depending on the specified fetch mode.
	 * @param string $query
	 * @param array|null $params
	 * @param int $fetchMode
	 * @return array|bool
	 */
	public function selectRow(string $query, ?array $params = null, int $fetchMode = PDO::FETCH_DEFAULT): array|bool
	{
		$sth = $this->query($query, $params);
		$row = $sth->fetch($fetchMode);
		$sth->closeCursor();
		return $row;
	}

	/**
	 * Fetch all rows from the database as an array of objects, associative arrays, or numeric arrays depending on the specified fetch mode.
	 * @param string $query
	 * @param array|null $params
	 * @param int $fetchMode
	 * @return array
	 */
	public function selectAll(string $query, ?array $params = null, int $fetchMode = PDO::FETCH_DEFAULT): array
	{
		$sth    = $this->query($query, $params);
		$result = $sth->fetchAll($fetchMode);
		$sth->closeCursor();
		return $result;
	}

	/**
	 * Return the number of rows affected by the last executed statement.
	 * @return int Number of affected rows, or 0 if no statement has been executed.
	 */
	public function rowCount(): int
	{
		return isset($this->statement) ? $this->statement->rowCount() : 0;
	}

	/**
	 * Driver's default row fetch mode — the mode ResultSet uses when no
	 * explicit mode is given. The value is fixed by the constructor options,
	 * so it is resolved once and cached; getAttribute() is not re-read for
	 * every result set.
	 */
	public function getDefaultFetchMode(): int
	{
		// Without an established driver handle (e.g. not yet connected,
		// or a test double that skips the constructor), fall back to the
		// framework's configured default.
		return $this->defaultFetchMode ??= isset($this->pdo)
			? $this->pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)
			: PDO::FETCH_OBJ;
	}

	/**
	 * Whether a transaction is currently active on this connection
	 * (including nested savepoint levels).
	 */
	public function inTransaction(): bool
	{
		return !empty($this->transactionLevel);
	}
	public function lastInsertId(?string $table = null, ?string $field = null): string|bool
	{
		if (!empty($table) && !empty($field) && $this->driverName === 'pgsql') {
			if ($table instanceof Model) {
				$schema = $table->schema();
				$table  = $table->source();
				if (!empty($schema)) {
					$table = "$schema.$table";
				}
			}
			$stmt = $this->pdo->prepare(
				"SELECT currval(pg_catalog.pg_get_serial_sequence(:table, :field))"
			);
			if ($stmt === false) {
				$info = $this->pdo->errorInfo();
				$ex   = new PDOException($info[2] ?? 'Unknown error');
				$ex->errorInfo = $info;
				throw $ex;
			}
			$stmt->execute(
				[
					':table' => $table,
					':field' => $field
				]
			);
			return $stmt->fetchColumn();
		}
		return $this->pdo->lastInsertId();
	}

	/**
	 * Begin a new transaction, or create a savepoint if nested transactions are enabled and a transaction is already active.
	 * @param bool $nesting Whether to use savepoints for nested transactions (if supported by the driver).
	 * @return bool|int True or the number of affected rows on success.
	 * @throws RuntimeException If the transaction cannot be started.
	 */
	public function begin(bool $nesting = true): bool|int
	{
		try {
			$this->transactionLevel++;
			if ($this->transactionLevel === 1) {
				$result = $this->pdo->beginTransaction();
			} elseif ($nesting) {
				switch ($this->driverName) {
					case 'mysql':
					case 'pgsql':
					case 'sqlite':
						$result = $this->pdo->exec(
							"SAVEPOINT trans$this->transactionLevel"
						);
						break;
					default:
						$result = $this->pdo->beginTransaction();
				}
			} else {
				return false;
			}
			if ($result === false) {
				$info = $this->pdo->errorInfo();
				$ex   = new PDOException($info[2] ?? 'Unknown error');
				$ex->errorInfo = $info;
				throw $ex;
			}
			return $result;
		} catch (PDOException $exception) {
			$this->processPdoException($exception, 'beginTransaction');
			throw $exception;
		} finally {
			$this->events()->dispatch(
				new TransactionStarted($this, $nesting, $this->transactionLevel)
			);
		}
	}

	/**
	 * Commit the current transaction or release the current savepoint (for nested transactions).
	 * @param bool $nesting Whether to use savepoints for nested transactions (if supported by the driver).
	 * @return bool|int True or the number of affected rows on success.
	 * @throws RuntimeException If there is no active transaction.
	 */
	public function commit(bool $nesting = true): bool|int
	{
		if ($this->transactionLevel === 0) {
			throw new RuntimeException(
				"There is no active transaction"
			);
		}
		try {
			$level = $this->transactionLevel--;
			if ($level === 1) {
				$result = $this->pdo->commit();
			} elseif ($nesting) {
				switch ($this->driverName) {
					case 'mysql':
					case 'pgsql':
					case 'sqlite':
						$result = $this->pdo->exec("RELEASE SAVEPOINT trans$level");
						break;
					default:
						$result = $this->pdo->commit();
				}
			} else {
				return false;
			}
			if ($result === false) {
				$info = $this->pdo->errorInfo();
				$ex   = new PDOException($info[2] ?? 'Unknown error');
				$ex->errorInfo = $info;
				throw $ex;
			}
			return $result;
		} catch (PDOException $exception) {
			$this->processPdoException($exception, 'commit');
			throw $exception;
		} finally {
			$this->events()->dispatch(
				new TransactionCommitted($this, $nesting, $this->transactionLevel)
			);
		}
	}

	/**
	 * Rollback the current transaction or to a savepoint if nesting is enabled and supported by the driver.
	 * @param bool $nesting Whether to use savepoints for nested transactions (if supported by the driver)
	 * @return bool|int
	 * @throws \Exception
	 */
	public function rollback(bool $nesting = true): bool|int
	{
		if ($this->transactionLevel === 0) {
			throw new RuntimeException(
				"There is no active transaction"
			);
		}
		try {
			$level = $this->transactionLevel--;
			if ($level === 1) {
				$result = $this->pdo->rollBack();
			} elseif ($nesting) {
				switch ($this->driverName) {
					case 'mysql':
					case 'pgsql':
					case 'sqlite':
						$result = $this->pdo->exec("ROLLBACK TO SAVEPOINT trans$level");
						break;
					default:
						$result = $this->pdo->rollBack();
				}
			} else {
				return false;
			}
			if ($result === false) {
				$info = $this->pdo->errorInfo();
				$ex   = new PDOException($info[2] ?? 'Unknown error');
				$ex->errorInfo = $info;
				throw $ex;
			}
			return $result;
		} catch (PDOException $exception) {
			$this->processPdoException($exception, 'rollback');
			throw $exception;
		} finally {
			$this->events()->dispatch(
				new TransactionRolledBack($this, $nesting, $this->transactionLevel)
			);
		}
	}

	/**
	 * Quote a string for use in a query.
	 * @param ?string $str
	 * @return string
	 */
	public function quote(?string $str): bool|string
	{
		if ($str === null) {
			return 'NULL';
		} else {
			return $this->pdo->quote($str);
		}
	}

	/**
	 * Quote one or more identifier parts (schema, table, column) using the driver-appropriate quote character.
	 * Parts are joined with a dot separator. NULL parts are skipped. "*" is passed through unquoted.
	 * @param string|null ...$args Identifier parts to quote and join (e.g. schema, table, column).
	 * @return string Fully quoted identifier string.
	 */
	public function quoteIdentifier(?string ...$args): string
	{
		$quoted = '';
		$sep    = '';
		foreach ($args as $arg) {
			if ($arg === null) {
				continue;
			}
			$quoted .= $sep;
			if ($arg === '*') {
				$quoted .= '*';
			} else {
				$quoted .= $this->quoteChar;
				$quoted .= str_replace(
					$this->quoteChar,
					$this->quoteChar . $this->quoteChar,
					$arg
				);
				$quoted .= $this->quoteChar;
			}
			$sep = '.';
		}
		return $quoted;
	}

	/**
	 * Return the underlying PDO connection instance.
	 * @return PDO|null The PDO instance, or null if not connected.
	 */
	public function getInternalConnection(): ?PDO
	{
		return $this->pdo;
	}

	/**
	 * Create a new Query builder instance associated with this database connection.
	 * @return Query
	 */
	public function builder(): Query
	{
		return new Query($this);
	}

	/**
	 * Whether the connected server supports the RETURNING clause on INSERT/UPDATE/DELETE.
	 *
	 * PostgreSQL supports it natively. MySQL 8.0.27+, MariaDB 10.5.0+ and SQLite 3.35+
	 * also support it. Older servers must fall back to lastInsertId() for ID backfilling.
	 *
	 * @return bool
	 */
	public function supportsReturning(): bool
	{
		static $supportsReturning = null;
		if ($supportsReturning === null) {
			switch ($this->driverName) {
				case 'pgsql':
					$supportsReturning = true;
					break;
				case 'mysql':
					// MariaDB connects through the PDO MySQL driver, so its
					// version string (e.g. "10.4.28-MariaDB") must be
					// detected separately.
					$version = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
					if (stripos($version, 'mariadb') !== false) {
						$supportsReturning = version_compare($version, '10.5.0', '>=');
					} else {
						$supportsReturning = version_compare($version, '8.0.27', '>=');
					}
					break;
				case 'sqlite':
					$version           = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
					$supportsReturning = version_compare((string) $version, '3.35.0', '>=');
					break;
				default:
					$supportsReturning = false;
			}
		}
		return $supportsReturning;
	}

	/**
	 * Return the lowercase database driver name extracted from the DSN (e.g. "mysql", "pgsql", "sqlite").
	 * @return string Driver name.
	 */
	public function getDriver(): string
	{
		return $this->driverName;
	}
}

// BC shim removed: all consumers reference Database/Database directly.

// BC shim removed: all consumers reference Database/Database directly.
