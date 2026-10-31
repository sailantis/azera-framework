<?php

namespace Azera\Db;

use Azera\AppContext;

/**
 * Build conditions for WHERE, HAVING, ON etc. clauses
 *
 * Usage examples:
 *
 *   // Simple condition
 *   $c = Condition::create()->where('id', 123);
 *
 *   // Qualified identifiers (automatically quoted)
 *   $c = Condition::create()->where('users.status', 'active');
 *
 *   // Large IN lists (no regex issues)
 *   $c = Condition::create()->inWhere('id', range(1, 10000));
 *
 *   // JOIN conditions
 *   $joinCond = Condition::create()->where('o.user_id = u.id');
 *   $sb->leftJoin('orders o', $joinCond);
 *
 *   // Complex conditions
 *   $c = Condition::create()
 *       ->where('u.age', 18, '>=')
 *       ->where('u.status', 'active')
 *       ->group(
 *           fn(Condition $g) =>
 *              $g->where('u.role', 'admin')
 *                  ->orWhere('u.role', 'moderator')
 *       );
 */
class Condition
{
	/**
	 * @var Database|null
	 */
	protected ?Database $db;

	/**
	 * Cached resolved Database for getDb() when $db is null.
	 * Resolved lazily once per instance; AppContext::dbManager()->getDefault()
	 * is memoized on the manager, so caching the result is safe.
	 */
	protected ?Database $resolvedDb = null;

	/**
	 * @var string
	 */
	protected string $condition = '';

	/**
	 * @var bool
	 */
	protected bool $needConnector = false;

	/**
	 * @var array
	 */
	protected array $subQueryBindings = [];

	/**
	 * @var callable|null Callable to resolve model names to table names
	 */
	protected $modelResolver = null;

	/**
	 * @var array Cache of model-to-table mappings
	 */
	protected array $tableCache = [];

	/**
	 * @var array<string,string> Deferred model prefixes (quotedModelDot => model name)
	 */
	protected array $deferredModelPrefixes = [];

	/**
	 * @var string
	 */
	protected ?string $finalCondition = null;

	/**
	 * Create a new Condition builder instance
	 * @param Database|null $db
	 * @return static
	 */
	public static function new(?Database $db = null): static
	{
		return new static($db);
	}

	/**
	 * @param ?Database $db
	 * @throws Exception
	 */
	public function __construct(?Database $db = null)
	{
		$this->db = $db;
	}

	/**
	 * Get database connection, resolving to default if not set
	 * @return Database
	 * @throws Exception
	 */
	protected function getDb(): Database
	{
		return $this->db ?? $this->resolvedDb ??= AppContext::instance()->dbManager()->getDefault();
	}

	/**
	 * Hook for resolving table names in Condition context
	 * @param string $model
	 * @return string
	 */
	protected function resolveTableNameOrDefer(string $model): string
	{
		// If we have a model resolver injected from Query builder, use it
		if ($this->modelResolver !== null) {
			try {
				return ($this->modelResolver)($model);
			} catch (\Exception $e) {}
		}

		// Defer model-like identifiers while keeping condition text readable.
		if (str_contains($model, '\\') || (isset($model[0]) && ctype_upper($model[0]))) {
			$quoted = $this->quoteIdentifier($model);
			$this->deferredModelPrefixes[$quoted . '.'] = $model;
			return $quoted;
		}

		// Otherwise treat it as alias/plain table name and escape immediately.
		return $this->quoteIdentifier($model);
	}

	/**
	 * Inject model resolver from Query builder
	 * @param callable $resolver Callable that takes model name and returns table name
	 * @return void
	 */
	public function injectModelResolver(callable $resolver): void
	{
		$this->modelResolver = $resolver;
	}

	/**
	 * Resolve deferred model tokens in SQL.
	 * Uses injected resolver when available, otherwise falls back to quoted model name.
	 * @param string $sql
	 * @return string
	 */
	protected function resolveDeferredModels(string $sql): string
	{
		if (empty($this->deferredModelPrefixes)) {
			return $sql;
		}

		$replacements = [];
		foreach ($this->deferredModelPrefixes as $prefix => $model) {
			if ($this->modelResolver !== null) {
				try {
					$replacements[$prefix] = ($this->modelResolver)($model) . '.';
					continue;
				} catch (\Exception $e) {}
			}
			$replacements[$prefix] = $prefix;
		}

		return strtr($sql, $replacements);
	}

	/**
	 * Appends a condition to the current conditions using an AND operator.
	 *
	 * Three forms:
	 *   where('col', $value)               equality (value escaped inline)
	 *   where('col', '=', $value)          explicit operator — BOUND param
	 *   where('age >=', 18)                operator embedded (legacy CI style)
	 *
	 * Supported operators in the explicit form: =, !=, <>, <, <=, >, >=,
	 * LIKE, NOT LIKE, IN, NOT IN. Values always go through bound
	 * parameters (never interpolation); IN () over an empty list compiles
	 * to the semantically correct 1=0 / 1=1.
	 *
	 * @param string|Condition $conditionOrField
	 * @param mixed $valueOrOp Value (CI style), or the operator token in the
	 *                         explicit operator form
	 * @param mixed $escapeOrValue Escape flag (CI style) — or the actual
	 *                             value in the explicit operator form
	 * @return $this
	 */
	public function where(string|Condition $conditionOrField, $valueOrOp = null, $escapeOrValue = true): static
	{
		return $this->addWhere($conditionOrField, ' AND ', '', $valueOrOp, $escapeOrValue);
	}

	/**
	 * Appends a condition to the current conditions using a OR operator
	 * @param string|Condition $conditionOrField
	 * @param mixed $valueOrOp
	 * @param mixed $escapeOrValue
	 * @return $this
	 */
	public function orWhere(string|Condition $conditionOrField, $valueOrOp = null, $escapeOrValue = true): static
	{
		return $this->addWhere($conditionOrField, ' OR ', '', $valueOrOp, $escapeOrValue);
	}

	/**
	 * Appends a negated condition to the current conditions using an AND operator
	 * @param string|Condition $conditionOrField
	 * @param mixed $valueOrOp
	 * @param mixed $escapeOrValue
	 * @return $this
	 */
	public function notWhere(string|Condition $conditionOrField, $valueOrOp = null, $escapeOrValue = true): static
	{
		return $this->addWhere($conditionOrField, ' AND ', 'NOT ', $valueOrOp, $escapeOrValue);
	}

	/**
	 * Appends a negated condition to the current conditions using an OR operator
	 * @param string|Condition $conditionOrField
	 * @param mixed $valueOrOp
	 * @param mixed $escapeOrValue
	 * @return $this
	 */
	public function orNotWhere(string|Condition $conditionOrField, $valueOrOp = null, $escapeOrValue = true): static
	{
		return $this->addWhere($conditionOrField, ' OR ', 'NOT ', $valueOrOp, $escapeOrValue);
	}

	/**
	 * Appends a condition to the current conditions using an operator.
	 *
	 * Operator detection: when $value is exactly an operator token
	 * (=, !=, <>, <, <=, >, >=, LIKE, NOT LIKE, IN, NOT IN), the call is
	 * the explicit three-argument form where $escape carries the REAL
	 * value; it is then bound as a named parameter instead of escaped
	 * inline.
	 *
	 * @param string|Condition $condition
	 * @param string $connector
	 * @param string $prefix
	 * @param mixed $value
	 * @param mixed $escape
	 * @return $this
	 */
	private function addWhere(string|Condition $condition, string $connector, string $prefix, $value = null, $escape = true): static
	{
		if (
			\is_string($condition) && \is_string($value)
				&& preg_match(self::OP_TOKENS, $value)
		) {
			$condition = $this->validateConditionField($condition);
			return $this->addWhereOp($condition, \strtoupper($value), $connector, $prefix, $escape);
		}

		if ($this->needConnector) {
			$this->condition .= $connector;
		}
		if ($condition instanceof Condition) {
			// sub conditions
			if ($this instanceof Query) {
				$condition->injectModelResolver(
					fn($model) => $this->getTableName($model)
				);
			}
			// merge bind parameters from sub conditions into current builder
			$this->subQueryBindings = $condition->getBindings() + $this->subQueryBindings;
			$escape    = false;
			$condition = $condition->toSql();
		} elseif (\is_array($value)) {
			// phalcon style
			$condition = $this->replacePlaceholders($condition, $value);
		} elseif (isset($value)) {
			if ($value instanceof Query) {
				// sub select
				// merge bind parameters from sub conditions into current builder
				$this->subQueryBindings = $value->getBindings() + $this->subQueryBindings;
				$escape = false;
				$value  = '(' . $value->toSql() . ')';
			} elseif ($value instanceof Condition) {
				// sub conditions - inject model resolver if available
				if ($this instanceof Query) {
					$value->injectModelResolver(
						fn($model) => $this->getTableName($model)
					);
				}
				// merge bind parameters from sub conditions into current builder
				$this->subQueryBindings = $value->getBindings() + $this->subQueryBindings;
				$escape = false;
				$value  = '(' . $value->toSql() . ')';
			}
			// ci style - protect identifier
			$condition = $this->protectIdentifier($condition);
			$condition = rtrim($condition);
			// If condition doesn't already end with an operator, add '='
			if (!preg_match('/(?:\b(?:NOT\s+)?(?:IN|LIKE|BETWEEN|REGEXP|SIMILAR\s+TO)|[=<>])$/i', $condition)) {
				$condition .= ' =';
			}
			$condition .= ' ';
			$condition .= $escape ? $this->escapeValue($value) : $value;
		} else {
			// Plain condition string - parse and protect identifiers
			$condition = $this->protectConditionString($condition);
		}
		$this->condition .= $prefix;
		$this->condition .= '(';
		$this->condition .= $condition;
		$this->condition .= ')';
		$this->needConnector = true;
		return $this;
	}

	/** Operator tokens recognized in the explicit where('col', OP, $value) form. */
	private const OP_TOKENS = '/^(?:=|!=|<>|<|<=|>|>=|LIKE|NOT LIKE|IN|NOT IN)$/i';

	/**
	 * Hook for subclasses (Query) to validate/normalize the identifier of
	 * an explicit three-argument where() before compilation. Default: pass
	 * through unchanged.
	 */
	protected function validateConditionField(string $condition): string
	{
		return $condition;
	}

	/**
	 * Compile an explicit three-argument where('col', OP, value) condition.
	 *
	 * Values are ALWAYS bound (named parameters :w0, :w1, …) — never
	 * interpolated. Empty IN lists compile to the semantically correct
	 * constant (IN () → 1=0, NOT IN () → 1=1); = / != with NULL use
	 * IS NULL / IS NOT NULL.
	 *
	 * @param string $condition column (or identifier expression)
	 * @param string $op        validated operator token (uppercased)
	 * @param string $connector AND/OR
	 * @param string $prefix    NOT prefix
	 * @param mixed  $value     the comparison value
	 */
	private function addWhereOp(string $condition, string $op, string $connector, string $prefix, $value): static
	{
		if ($this->needConnector) {
			$this->condition .= $connector;
		}

		$col = $this->protectIdentifier($condition);

		// IN / NOT IN: expand to placeholders; empty set → constant SQL.
		if ($op === 'IN' || $op === 'NOT IN') {
			$list = \is_array($value) ? \array_values($value) : [$value];
			if ($list === []) {
				// Semantically exact constants: IN over nothing is never
				// true, NOT IN over nothing always is.
				$this->condition .= $prefix . '(' . ($op === 'IN' ? '1=0' : '1=1') . ')';
			} else {
				$names = [];
				foreach ($list as $v) {
					$name = 'w' . $this->nextOpBind();
					$this->subQueryBindings[$name] = $v;
					$names[] = ':' . $name;
				}
				$this->condition .= $prefix . '(' . $col . ' ' . $op . ' (' . implode(', ', $names) . '))';
			}
			$this->needConnector = true;
			return $this;
		}

		// NULL comparisons for the equality family.
		if ($value === null && \in_array($op, ['=', '!=', '<>'], true)) {
			$sql = $col . (($op === '=') ? ' IS NULL' : ' IS NOT NULL');
			$this->condition .= $prefix . '(' . $sql . ')';
			$this->needConnector = true;
			return $this;
		}

		// Single bound parameter: = != <> < <= > >= LIKE NOT LIKE.
		$name = 'w' . $this->nextOpBind();
		$this->subQueryBindings[$name] = $value;
		$this->condition .= $prefix . '(' . $col . ' ' . $op . ' :' . $name . ')';
		$this->needConnector = true;
		return $this;
	}

	/** Monotonic counter for unique :wN operator-bind names. */
	private int $opBindCounter = 0;

	private function nextOpBind(): int
	{
		return $this->opBindCounter++;
	}

	/**
	 * Appends a BETWEEN condition to the current conditions using AND operator
	 * @param string $condition
	 * @param $minimum
	 * @param $maximum
	 * @return $this
	 */
	public function betweenWhere(string $condition, $minimum, $maximum): static
	{
		return $this->addBetweenWhere($condition, ' AND ', ' BETWEEN ', $minimum, $maximum);
	}

	/**
	 * Appends a NOT BETWEEN condition to the current conditions using AND operator
	 * @param string $condition
	 * @param $minimum
	 * @param $maximum
	 * @return $this
	 */
	public function notBetweenWhere(string $condition, $minimum, $maximum): static
	{
		return $this->addBetweenWhere($condition, ' AND ', ' NOT BETWEEN ', $minimum, $maximum);
	}

	/**
	 * Appends a BETWEEN condition to the current conditions using OR operator
	 * @param string $condition
	 * @param $minimum
	 * @param $maximum
	 * @return $this
	 */
	public function orBetweenWhere(string $condition, $minimum, $maximum): static
	{
		return $this->addBetweenWhere($condition, ' OR ', ' BETWEEN ', $minimum, $maximum);
	}

	/**
	 * Appends a NOT BETWEEN condition to the current conditions using OR operator
	 * @param string $condition
	 * @param $minimum
	 * @param $maximum
	 * @return $this
	 */
	public function orNotBetweenWhere(string $condition, $minimum, $maximum): static
	{
		return $this->addBetweenWhere($condition, ' OR ', ' NOT BETWEEN ', $minimum, $maximum);
	}

	/**
	 * Appends a BETWEEN condition to the current conditions
	 * @param string $condition
	 * @param string $connector
	 * @param string $between
	 * @param $minimum
	 * @param $maximum
	 * @return $this
	 */
	private function addBetweenWhere(
		string $condition,
		string $connector,
		string $between,
		$minimum,
		$maximum
	): static {
		if ($this->needConnector) {
			$this->condition .= $connector;
		}
		$this->condition .= '(' . $this->protectIdentifier($condition) . $between . $this->escapeValue($minimum) . ' AND ' . $this->escapeValue($maximum) . ')';
		$this->needConnector = true;
		return $this;
	}

	/**
	 * Appends an IN condition to the current conditions using AND operator
	 * @param string $condition
	 * @param $values
	 * @return $this
	 */
	public function inWhere(string $condition, $values): static
	{
		return $this->addInWhere($condition, ' AND ', 'IN', $values);
	}

	/**
	 * Appends an NOT IN condition to the current conditions using AND operator
	 * @param string $condition
	 * @param $values
	 * @return $this
	 */
	public function notInWhere(string $condition, $values): static
	{
		return $this->addInWhere($condition, ' AND ', 'NOT IN', $values);
	}

	/**
	 * Appends an IN condition to the current conditions using OR operator
	 * @param string $condition
	 * @param $values
	 * @return $this
	 */
	public function orInWhere(string $condition, $values): static
	{
		return $this->addInWhere($condition, ' OR ', 'IN', $values);
	}

	/**
	 * Appends an NOT IN condition to the current conditions using OR operator
	 * @param string $condition
	 * @param $values
	 * @return $this
	 */
	public function orNotInWhere(string $condition, $values): static
	{
		return $this->addInWhere($condition, ' OR ', 'NOT IN', $values);
	}

	/**
	 * Appends an NOT IN condition to the current conditions
	 * @param string $condition
	 * @param string $connector
	 * @param string $in
	 * @param $values
	 * @return $this
	 */
	private function addInWhere(string $condition, string $connector, string $in, $values): static
	{
		if ($this->needConnector) {
			$this->condition .= $connector;
		}
		$protectedCondition = $this->protectIdentifier($condition);
		if ($values instanceof Condition) {
			if ($this instanceof Query) {
				$values->injectModelResolver(
					fn($model) => $this->getTableName($model)
				);
			}
			$this->subQueryBindings = $values->getBindings() + $this->subQueryBindings;
			$this->condition .= '(' . $protectedCondition . " $in (" . $values->toSql() . '))';
		} else {
			$this->condition .= '(' . $protectedCondition . " $in (" . $this->escapeValue($values) . '))';
		}
		$this->needConnector = true;
		return $this;
	}

	/**
	 * Appends an HAVING condition to the current conditions using AND operator
	 * @param string|Sql $condition
	 * @param array|null $values
	 * @return $this
	 */
	public function having(string|Sql $condition, $values = null): static
	{
		return $this->addHaving($condition, ' AND ', 'HAVING', $values);
	}

	/**
	 * Appends an NOT HAVING condition to the current conditions using AND operator
	 * @param string|Sql $condition
	 * @param array|null $values
	 * @return $this
	 */
	public function notHaving(string|Sql $condition, $values = null): static
	{
		return $this->addHaving($condition, ' AND ', 'NOT HAVING', $values);
	}

	/**
	 * Appends an HAVING condition to the current conditions using OR operator
	 * @param string|Sql $condition
	 * @param array|null $values
	 * @return $this
	 */
	public function orHaving(string|Sql $condition, $values = null): static
	{
		return $this->addHaving($condition, ' OR ', 'HAVING', $values);
	}

	/**
	 * @param string|Sql $condition
	 * @param array|null $values
	 * @return $this
	 **/
	public function orNotHaving(string|Sql $condition, $values = null): static
	{
		return $this->addHaving($condition, ' OR ', 'NOT HAVING', $values);
	}

	/**
	 * Appends an HAVING condition to the current conditions
	 * @param string|Sql $condition
	 * @param string $connector
	 * @param string $having
	 * @param array|null $values
	 * @return $this
	 */
	private function addHaving(string|Sql $condition, string $connector, string $having, $values = null): static
	{
		if ($this->needConnector) {
			$this->condition .= $connector;
		}
		// If a Sql was provided directly, serialize it and ignore $values
		if ($condition instanceof Sql) {
			$condition = $this->serializeScalar($condition);
		} elseif (\is_array($values)) {
			// phalcon style
			$condition = $this->replacePlaceholders($condition, $values);
		}

		$this->condition .= "($having $condition)";
		$this->needConnector = true;
		return $this;
	}

	/**
	 * Appends a LIKE condition to the current condition
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @return $this
	 */
	public function likeWhere(string $identifier, $value, bool $escape = true): static
	{
		$this->addLikeWhere($identifier, $value, $escape, " AND ", " LIKE ");
		return $this;
	}

	/**
	 * Appends a LIKE condition to the current condition using an AND operator
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @return $this
	 */
	public function andLikeWhere(string $identifier, $value, bool $escape = true): static
	{
		$this->addLikeWhere($identifier, $value, $escape, " AND ", " LIKE ");
		return $this;
	}

	/**
	 * Appends a LIKE condition to the current condition using an OR operator
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @return $this
	 */
	public function orLikeWhere(string $identifier, $value, bool $escape = true): static
	{
		$this->addLikeWhere($identifier, $value, $escape, " OR ", " LIKE ");
		return $this;
	}

	/**
	 * Appends a NOT LIKE condition to the current condition
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @return $this
	 */
	public function notLikeWhere(string $identifier, $value, bool $escape = true): static
	{
		$this->addLikeWhere($identifier, $value, $escape, " AND ", " NOT LIKE ");
		return $this;
	}

	/**
	 * Appends a NOT LIKE condition to the current condition using an AND operator
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @return $this
	 */
	public function andNotLikeWhere(string $identifier, $value, bool $escape = true): static
	{
		$this->addLikeWhere($identifier, $value, $escape, " AND ", " NOT LIKE ");
		return $this;
	}

	/**
	 * Appends a NOT LIKE condition to the current condition using an OR operator
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @return $this
	 */
	public function orNotLikeWhere(string $identifier, $value, bool $escape = true): static
	{
		return $this->addLikeWhere($identifier, $value, $escape, " OR ", " NOT LIKE ");
	}

	/**
	 * Appends a LIKE condition to the current condition
	 * @param string $identifier
	 * @param $value
	 * @param bool $escape
	 * @param string $connector
	 * @param string $like
	 * @return $this
	 */
	private function addLikeWhere(
		string $identifier,
		$value,
		bool $escape,
		string $connector,
		string $like
	): static {
		if ($this->needConnector) {
			$this->condition .= $connector;
		}
		$this->condition .= '(';
		$this->condition .= $this->protectIdentifier($identifier);
		$this->condition .= $like;
		$this->condition .= $escape ? $this->escapeValue($value) : $value;
		$this->condition .= ')';
		$this->needConnector = true;
		return $this;
	}

	/**
	 * Build a grouped condition using a callback.
	 *
	 * The callback receives a fresh Condition builder whose contents are
	 * wrapped in parentheses and appended to the current builder using AND.
	 * Bindings and deferred model prefixes are merged into the parent.
	 *
	 * Example:
	 *   $c->group(function (Condition $g) {
	 *       $g->where('role', 'admin')
	 *         ->orWhere('role', 'moderator');
	 *   });
	 *
	 * @param callable(Condition): void $callback
	 * @return $this
	 */
	public function group(callable $callback): static
	{
		return $this->addGroup($callback, ' AND ', '(');
	}

	/**
	 * Build a grouped condition using a callback, joined with OR.
	 *
	 * @param callable(Condition): void $callback
	 * @return $this
	 * @see group()
	 */
	public function orGroup(callable $callback): static
	{
		return $this->addGroup($callback, ' OR ', '(');
	}

	/**
	 * Build a negated grouped condition using a callback, joined with AND.
	 *
	 * @param callable(Condition): void $callback
	 * @return $this
	 * @see group()
	 */
	public function notGroup(callable $callback): static
	{
		return $this->addGroup($callback, ' AND ', 'NOT (');
	}

	/**
	 * Build a negated grouped condition using a callback, joined with OR.
	 *
	 * @param callable(Condition): void $callback
	 * @return $this
	 * @see group()
	 */
	public function orNotGroup(callable $callback): static
	{
		return $this->addGroup($callback, ' OR ', 'NOT (');
	}

	/**
	 * Shared implementation for the callback-based group methods.
	 *
	 * @param callable(Condition): void $callback
	 * @param string $connector Logical operator to prepend (' AND ' or ' OR ')
	 * @param string $open Opening token ('(' or 'NOT (')
	 * @return $this
	 */
	private function addGroup(callable $callback, string $connector, string $open): static
	{
		if ($this->needConnector) {
			$this->condition .= $connector;
		}

		$this->condition .= $open;
		$this->needConnector = false; // first inner condition: no prefix

		$callback($this);

		$this->condition .= ')';
		$this->needConnector = true; // next sibling: gets AND/OR

		return $this;
	}

	/**
	 * No operator function. Useful to build flexible chains
	 * @return $this
	 */
	public function noop(): static
	{
		return $this;
	}

	/**
	 * Replace placeholders with escaped values.
	 *
	 * Supports both positional (?) and named (:name) placeholders. The
	 * template is scanned exactly once, left to right. Already substituted
	 * values are never re-scanned, so a placeholder character contained in
	 * an escaped value (or in a quoted literal of the template) can never be
	 * turned into a new placeholder — doing so would place attacker text
	 * outside its string literal and allow SQL injection.
	 *
	 * @param string $condition
	 * @param array|null $bindParams
	 * @return string
	 */
	protected function replacePlaceholders(string $condition, ?array $bindParams): string
	{
		if (empty($bindParams)) {
			return $condition;
		}

		$positional = [];
		$named      = [];

		foreach ($bindParams as $key => $value) {
			if (is_int($key)) {
				$positional[] = $this->serializeScalar($value);
			} else {
				$named[(string) $key] = $this->serializeScalar($value);
			}
		}

		$result = '';
		$length = strlen($condition);
		$next   = 0;
		$pos    = 0;

		while ($pos < $length) {
			$char = $condition[$pos];

			// Skip over quoted literals: placeholders inside them are data.
			if ($char === "'" || $char === '"') {
				$quote = $char;
				$end   = $pos + 1;

				while ($end < $length) {
					if ($condition[$end] === $quote) {
						// SQL escapes a quote by doubling it.
						if ($end + 1 < $length && $condition[$end + 1] === $quote) {
							$end += 2;
							continue;
						}
						break;
					}
					$end++;
				}

				$result .= substr($condition, $pos, $end - $pos + 1);
				$pos = $end + 1;
				continue;
			}

			if ($char === '?') {
				if (array_key_exists($next, $positional)) {
					$result .= $positional[$next++];
				} else {
					$result .= $char;
				}
				$pos++;
				continue;
			}

			if ($char === ':') {
				if (preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $condition, $m, 0, $pos)) {
					$name = $m[1];
					if (array_key_exists($name, $named)) {
						$result .= $named[$name];
						$pos += strlen($m[0]);
						continue;
					}
				}
				$result .= $char;
				$pos++;
				continue;
			}

			$result .= $char;
			$pos++;
		}

		return $result;
	}

	/**
	 * Serialize a value to SQL (handles Sql instances)
	 * @param mixed $value
	 * @return string
	 */
	protected function serializeScalar($value): string
	{
		// Sql instances serialize themselves
		if ($value instanceof Sql) {

			$result = $value->toSql(
				$this->getDb()->getDriver(),
				fn($v) => $this->serializeScalar($v),
				fn($identifier) => $this->protectIdentifier($identifier, self::PI_COLUMN)
			);

			// merge bind parameters from node into current builder
			$nodeBindParams = $value->getBindParams();
			if (!empty($nodeBindParams)) {
				if ($value->usesPdoBinding()) {
					// PDO path: keep :name placeholder in SQL, bubble value up
					// so it reaches Database::query() as a real named parameter
					$this->subQueryBindings = $nodeBindParams + $this->subQueryBindings;
				} else {
					// Inline path: replace :name with escaped literal
					$result = $this->replacePlaceholders(
						$result,
						$nodeBindParams
					);
				}
			}

			return $result;
		}

		return $this->escapeValue($value);
	}

	/**
	 * Escape a value
	 * @param mixed $value
	 * @return string
	 */
	protected function escapeValue($value)
	{
		// Sql nodes serialize themselves (and bubble PDO bindings if applicable)
		if ($value instanceof Sql) {
			return $this->serializeScalar($value);
		}

		// PostgreSQL Array Support
		if (is_array($value)) {
			// special array with value + escape flag
			$isSpecialArray =
				count($value) === 2 &&
					isset($value['value']) &&
					isset($value['escape']);

			if ($isSpecialArray) {
				return $value['escape']
					? $this->escapeValue($value['value'])
					: (string) $value['value'];
			}

			$result = "";
			$sep    = "";
			foreach ($value as $v) {
				$result .= $sep;
				$sep = ",";
				$result .= $this->escapeValue($v);
			}
			return $result;
		}

		// scalars
		if ($value === null) {
			return 'NULL';
		}

		if (is_int($value) || is_float($value)) {
			return $value;
		}

		if (is_bool($value)) {
			if ($this->getDb()->getDriver() === 'pgsql') {
				return $value ? 'TRUE' : 'FALSE';
			}
			return $value ? '1' : '0';
		}

		if ($value instanceof \DateTimeInterface) {
			return $this->getDb()->quote($value->format('Y-m-d H:i:s'));
		}

		if ($value instanceof \BackedEnum) {
			return $this->escapeValue($value->value);
		}

		if ($value instanceof \UnitEnum) {
			return $this->escapeValue($value->name);
		}

		if ($value instanceof Query) {
			// sub select
			// merge auto-bind parameters from sub select into current builder
			$this->subQueryBindings = $value->getBindings() + $this->subQueryBindings;
			return '(' . $value->toSql() . ')';
		}

		if ($value instanceof Condition) {
			// sub conditions - inject model resolver if available
			if ($this instanceof Query) {
				$value->injectModelResolver(function ($model) {
					return $this->getTableName($model);
				});
			}
			// merge auto-bind parameters from sub conditions into current builder
			$this->subQueryBindings = $value->getBindings() + $this->subQueryBindings;
			return '(' . $value->toSql() . ')';
		}

		return $this->getDb()->quote((string) $value);
	}

	/**
	 * Quote column and table name
	 * @param string $item
	 * @return string
	 */
	protected function quoteIdentifier(string $item): string
	{
		if (empty($item) || $item == '*') {
			return $item;
		}
		// Avoid breaking functions and literal values inside queries
		if (ctype_digit($item) || $item[0] === "'" || $item[0] === '"' || str_contains($item, '(')) {
			return $item;
		}
		return $this->getDb()->quoteIdentifier($item);
	}

	/**
	 * Get table name from cache or resolve it
	 * @param string $model Model or table reference
	 * @return string Resolved table name or marker for deferred resolution
	 */
	protected function getTableName(string $model): string
	{
		if (isset($this->tableCache[$model])) {
			return $this->tableCache[$model];
		}
		// Hook for subclasses to handle resolution
		return $this->resolveTableNameOrDefer($model);
	}

	protected const PI_DEFAULT = 0;
	protected const PI_COLUMN = 1;
	protected const PI_TABLE = 2;

	/**
	 * Memoization cache for protectIdentifier() results (PI_DEFAULT mode only).
	 * Real queries repeat the same table-qualified columns over and over, so this
	 * collapses the full preg_replace/alias-split/quote pipeline into a hash hit.
	 * Per-instance (never static): builders are request-scoped, and Query's
	 * model resolution stays dynamic via getFullTableName().
	 *
	 * @var array<string,string>
	 */
	private array $identifierMemo = [];

	/**
	 * Protect identifier (field or table name)
	 * @param string $item
	 * @param int $type
	 * @param string|null $alias
	 * @return string
	 */
	protected function protectIdentifier(
		string $item,
		int $type = self::PI_DEFAULT,
		?string $alias = null
	): string {
		// Memoize the common default-mode, no-alias calls: deterministic and
		// repeated heavily within one query build.
		if ($type === self::PI_DEFAULT && $alias === null) {
			return $this->identifierMemo[$item] ??= $this->doProtectIdentifier($item);
		}
		return $this->doProtectIdentifier($item, $type, $alias);
	}

	/**
	 * Core identifier protection pipeline (no memoization).
	 */
	private function doProtectIdentifier(
		string $item,
		int $type = self::PI_DEFAULT,
		?string $alias = null
	): string {
		// Normalize whitespace: trim and replace multiple spaces/tabs/newlines with single space
		$item = preg_replace('/\s+/', ' ', trim($item));

		// Extract alias from "item AS alias" or "item alias"
		// Orderby/Groupby can have ASC/DESC as "alias"
		if (!isset($alias)) {
			if ($offset = strripos($item, ' AS ')) {
				$alias = substr($item, $offset + 4);
				$item  = substr($item, 0, $offset);
			} elseif ($offset = strrpos($item, ' ')) {
				$alias = substr($item, $offset + 1);
				$item  = substr($item, 0, $offset);
			}
		}

		// Only process items without functions, quotes, or parentheses
		if (strcspn($item, "()'") === strlen($item)) {

			if ($type === self::PI_TABLE) {
				// Table mode: use full table name resolution with alias support
				return $this->getFullTableName($item, $alias);
			}

			// Column mode: handle qualified identifiers (table.column)
			$index = strpos($item, '.');
			if ($index > 0) {
				$table = $this->getTableName(substr($item, 0, $index));
				$item  = $table . '.' . $this->quoteIdentifier(substr($item, $index + 1));
			} else {
				$item = $this->quoteIdentifier($item);
			}
		}

		// Add alias
		if (!empty($alias)) {
			if ($type === self::PI_TABLE || $type === self::PI_COLUMN) {
				$item .= ' AS ' . $this->quoteIdentifier($alias);
			} else {
				$item .= ' ' . $alias;
			}
		}

		return $item;
	}

	/**
	 * Parse and protect identifiers in a simple condition string
	 * Handles basic operators: =, !=, <, >, <=, >=, <>, IS NULL, IS NOT NULL
	 * @param string $condition
	 * @return string
	 */
	protected function protectConditionString(string $condition, bool $internal = false): string
	{
		// Split compound conditions on top-level AND / OR and process each part recursively
		if (!$internal) {
			$parts = $this->splitConditionOnLogicalOperators($condition);
			if (count($parts) > 1) {
				$result = "";
				foreach ($parts as [$part, $glue]) {
					$result .= $this->protectConditionString(trim($part), true);
					if ($glue !== null) {
						$result .= $glue;
					}
				}
				return $result;
			}
		}

		// List of operators to search for (order matters - longer first)
		$operators = [
			'IS NOT NULL',
			'IS NULL',
			'!=',
			'<=',
			'>=',
			'<>',
			'=',
			'<',
			'>'
		];

		foreach ($operators as $op) {

			$pos = stripos($condition, $op);
			if ($pos === false) {
				continue;
			}

			$lhs = trim(substr($condition, 0, $pos));
			$rhs = trim(substr($condition, $pos + strlen($op)));

			// Protect LHS (always an identifier)
			$lhs = $this->protectIdentifier($lhs);

			// Protect RHS if it looks like an identifier (not a literal, not empty for IS NULL)
			if (
				!empty($rhs) &&
					!is_numeric($rhs) &&
					$rhs[0] !== "'" &&
					$rhs[0] !== '"' &&
					$rhs[0] !== '(' &&
					strpos($rhs, ':') === false
			) {
				$rhs = $this->protectIdentifier($rhs);
			}

			return $lhs . ' ' . $op . (!empty($rhs) ? ' ' . $rhs : '');
		}

		// No operator found - return as-is
		return $condition;
	}

	/**
	 * Split a condition string on top-level AND / OR keywords (respecting parentheses depth).
	 * Returns an array of [part, glue] pairs where glue is 'AND', 'OR', or null for the last part.
	 */
	protected function splitConditionOnLogicalOperators(string $condition): array
	{
		$parts = [];
		$len   = strlen($condition);
		$upper = strtoupper($condition); // single uppercase copy for comparisons
		$depth = 0;
		$start = 0;
		$i     = 0;

		while ($i < $len) {
			$ch = $condition[$i];

			if ($ch === '(') {
				$depth++;
				$i++;
				continue;
			}
			if ($ch === ')') {
				$depth = max(0, $depth - 1);
				$i++;
				continue;
			}

			if ($depth === 0) {
				// Check for " AND " or " OR " at current position using substr_compare on uppercase version for case-insensitive match
				if ($i + 5 <= $len && substr_compare($upper, ' AND ', $i, 5, true) === 0) {
					$part = trim(substr($condition, $start, $i - $start));
					$parts[] = [$part, ' AND '];
					$i += 5;
					$start = $i;
					continue;
				}
				if ($i + 4 <= $len && substr_compare($upper, ' OR ', $i, 4, true) === 0) {
					$part = trim(substr($condition, $start, $i - $start));
					$parts[] = [$part, ' OR '];
					$i += 4;
					$start = $i;
					continue;
				}
			}

			$i++;
		}

		// Add final part
		$final = trim(substr($condition, $start));
		if ($final !== '' || !empty($parts)) {
			$parts[] = [$final, null];
		}

		return $parts;
	}

	/**
	 * Get full table name with model resolution (only available in Query builder context)
	 * @param string $modelName
	 * @param string|null $alias
	 * @return string
	 */
	protected function getFullTableName(string $modelName, ?string $alias): string
	{
		throw new \BadFunctionCallException('getFullTableName() is only available in Query builder context');
	}

	/**
	 * Replace placeholders in the condition with actual values
	 * @param array $bindParams
	 * @return $this
	 */
	public function bind(array $bindParams): static
	{
		$this->finalCondition = $this->replacePlaceholders(
			$this->condition,
			$bindParams + $this->getBindings()
		);
		return $this;
	}

	/**
	 * Get the condition
	 * @return string
	 */
	public function toSql(): string
	{
		$sql = $this->finalCondition ?? $this->condition;
		return $this->resolveDeferredModels($sql);
	}

	/**
	 * Get bind parameters
	 */
	public function getBindings(): array
	{
		return $this->subQueryBindings;
	}
}
