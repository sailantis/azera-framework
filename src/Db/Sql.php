<?php

namespace Azera\Db;

/**
 * SQL Value Object - Tagged Union for SQL Expressions
 * 
 * Represents SQL expressions (functions, casts, arrays, etc.) that serialize at SQL generation time.
 * Default behavior: serialize to literals (debug-friendly)
 * Sql::param() creates a named binding reference (:name) for use with Query::bind()
 * 
 * @example
 * 
 * // Function with literals
 * Sql::func('concat', ['prefix_', 'value'])
 * // → concat('prefix_', 'value')
 * 
 * // Function with named binding reference (value supplied via Query::bind())
 * Sql::func('concat', ['prefix_', Sql::param('id')])
 * // → concat('prefix_', :id)
 * 
 * // PostgreSQL array
 * Sql::pgArray(['php', 'pgsql'])
 * // → '{"php","pgsql"}'
 * 
 * // Cast (driver-specific)
 * Sql::cast(Sql::column('text_search'), 'tsvector')
 * // PostgreSQL: text_search::tsvector
 * // MySQL: CAST(text_search AS tsvector)
 */
class Sql
{
    protected const TYPE_COLUMN = 1;
    protected const TYPE_PARAM = 2;
    protected const TYPE_FUNC = 3;
    protected const TYPE_CAST = 4;
    protected const TYPE_PG_ARRAY = 5;
    protected const TYPE_CS_LIST = 6;
    protected const TYPE_RAW = 7;
    protected const TYPE_JSON = 8;
    protected const TYPE_CONCAT = 9;
    protected const TYPE_EXPR = 10;
    protected const TYPE_ALIAS = 11;
    protected const TYPE_VALUE = 12;
    protected const TYPE_SUBQUERY = 13;

    protected int $type;
    protected mixed $value;
    protected array $args;
    protected ?string $cast;
    protected array $bindParams = [];
    protected bool $mustResolve = false;
    protected ?string $alias = null;

    /**
     * @param int $type Node type: column, param, func, cast, pg_array, in_list, raw
     * @param mixed $value Primary value for the node
     * @param array $args Function arguments (for func type)
     * @param string|null $cast Cast type name (for cast type)
     */
    protected function __construct(
        int $type,
        mixed $value = null,
        array $args = [],
        ?string $cast = null
    )
    {
        $this->type = $type;
        $this->value = $value;
        $this->args = $args;
        $this->cast = $cast;
    }

    /**
     * Column reference (unquoted identifier)
     * Supports Model.column syntax for automatic table resolution
     * @param string $name Column name (simple or Model.column format)
     * @return $this
     */
    public static function column(string $name): static
    {
        $node = new static(static::TYPE_COLUMN, $name);
        // Flag for Model.column resolution if dot notation detected
        if (strpos($name, '.') !== false) {
            $node->mustResolve = true;
        }
        return $node;
    }

    /**
     * Named binding reference — emits :name in the SQL, resolved against
     * the manual bindings supplied via Query::bind().
     * @param string $name Parameter name (must match a key in bind())
     * @return $this
     */
    public static function param(string $name): static
    {
        return new static(static::TYPE_PARAM, $name);
    }

    /**
     * Bound parameter — emits :name in the SQL and propagates the value as a
     * real PDO named parameter (not inlined as an escaped literal).
     * The value is merged into Query::$subQueryBindings and reaches
     * Database::query() via PDO execute().
     * @param string $name Parameter name
     * @param mixed $value Parameter value
     * @return $this
     */
    public static function bind(string $name, mixed $value): static
    {
        $node = new static(static::TYPE_PARAM, $name);
        $node->bindParams[$name] = $value;
        return $node;
    }

    /**
     * Whether this node's bind parameters should be passed as real PDO named
     * parameters rather than inlined as escaped literals.
     * @return bool
     */
    public function usesPdoBinding(): bool
    {
        return $this->type === static::TYPE_PARAM && !empty($this->bindParams);
    }

    /**
     * SQL function call
     * @param string $name Function name
     * @param array $args Function arguments (scalars or Sql instances)
     * @return $this
     */
    public static function func(string $name, array $args = []): static
    {
        return new static(static::TYPE_FUNC, $name, $args);
    }

    /**
     * Type cast (driver-specific syntax)
     * @param mixed $value Value to cast (scalar or Sql)
     * @param string $type Target type name
     * @return $this
     */
    public static function cast(mixed $value, string $type): static
    {
        return new static(static::TYPE_CAST, $value, [], $type);
    }

    /**
     * PostgreSQL array literal
     * @param array $values Array elements (scalars or Sql instances)
     * @return $this
     */
    public static function pgArray(array $values): static
    {
        return new static(static::TYPE_PG_ARRAY, $values);
    }

    /**
     * Comma-separated list (for IN clauses)
     * @param array $values List elements (scalars or Sql instances)
     * @return $this
     */
    public static function csList(array $values): static
    {
        return new static(static::TYPE_CS_LIST, $values);
    }

    /**
     * Raw SQL (unescaped, passed through as-is)
     * @param string $sql Raw SQL string
     * @param array $inlineValues Optional values to be replaced in the SQL (e.g. for :name placeholders), treated as literal values (escaped)
     * @return $this
     */
    public static function raw(string $sql, array $inlineValues = []): static
    {
        $node = new static(static::TYPE_RAW, $sql);
        $node->bindParams = $inlineValues;
        return $node;
    }

    /**
     * Literal value (will be properly quoted/escaped)
     * @param mixed $value Value to serialize as SQL literal
     * @return $this
     */
    public static function value(mixed $value): static
    {
        return new static(static::TYPE_VALUE, $value);
    }

    /**
     * JSON value (serialized as JSON literal)
     * @param array $value Value to encode as JSON
     * @return $this
     */
    public static function json(mixed $value): static
    {
        return new static(static::TYPE_JSON, $value);
    }

    /**
     * Driver-aware string concatenation
     * PostgreSQL/SQLite: uses || operator
     * MySQL: uses CONCAT() function
     * @param mixed ...$parts Parts to concatenate (scalars or Sql instances)
     * @return $this
     */
    public static function concat(...$parts): static
    {
        return new static(static::TYPE_CONCAT, $parts);
    }

    /**
     * Composite expression - concatenates parts with spaces
     * Useful for complex expressions like CASE WHEN
     * Plain strings are treated as raw SQL tokens (not serialized)
     * @param mixed ...$parts Expression parts (strings are raw, use Sql instances for values)
     * @return $this
     */
    public static function expr(...$parts): static
    {
        return new static(static::TYPE_EXPR, $parts);
    }

    /**
     * CASE expression builder
     * @return SqlCase Fluent builder for CASE expressions
     */
    public static function case(): SqlCase
    {
        return new SqlCase();
    }

    /**
     * Subquery expression - wraps a Query instance as a subquery
     * @param Query $query Subquery instance
     * @return $this
     */
    public static function subQuery(Query $query): static
    {
        return new static(static::TYPE_SUBQUERY, $query);
    }

    /**
     * Add alias to this expression (returns aliased node)
     * @param string $alias Column alias
     * @return $this
     */
    public function as(string $alias): static
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Get bind parameters associated with this node
     * @return array Associative array of bind parameters
     */
    public function getBindParams(): array
    {
        return $this->bindParams;
    }

    /**
     * Serialize PostgreSQL array recursively for multi-dimensional support
     *
     * The array literal is wrapped in single quotes, so every element must
     * also escape single quotes and backslashes for the outer string literal
     * in addition to the array-level `"` / `\` escaping. Omitting that let an
     * element containing an apostrophe terminate the literal.
     *
     * @return string PostgreSQL array literal
     */
    protected function serializePgArray(): string
    {
        $serializeValue = function($value) {
            if (\is_null($value)) {
                return 'NULL';
            }
            if (\is_bool($value)) {
                return $value ? 'TRUE' : 'FALSE';
            }
            if (\is_int($value) || \is_float($value)) {
                return (string)$value;
            }

            // PostgreSQL-compatible escaping for strings inside the array:
            // backslash and double quote need array-level escaping.
            $v = (string)$value;
            $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);

            // The whole literal is wrapped in single quotes, so a single quote
            // must be doubled once more for that outer string literal
            // (standard_conforming_strings = on, the PostgreSQL default).
            $v = str_replace("'", "''", $v);

            return '"' . $v . '"';
        };

        $serializeArray = function($array) use (&$serializeArray, $serializeValue) {
            $result = "";
            $sep = "";
            foreach ($array as $item) {
                $result .= $sep;
                $sep = ",";
                if (is_array($item)) {
                    $result .= $serializeArray($item);
                } else {
                    $result .= $serializeValue($item);
                }
            }
            return '{' . $result . '}';
        };

        return "'" . $serializeArray($this->value) . "'";
    }

    /**
     * Serialize node to SQL string
     *
     * @param string $driver Database driver (mysql, pgsql, sqlite)
     * @param callable $serialize Callback for serializing scalar values
     *                            Signature: fn(mixed $value, bool $param = false): string
     * @param callable|null $protectIdentifier Callback for identifier resolution and quoting
     *                                         Signature: fn(string $identifier, ?string $alias = null, int $mode = 0): string
     *                                         If not provided, falls back to simple driver-based quoting
     * @return string SQL fragment
     */
    public function toSql(string $driver, callable $serialize, ?callable $protectIdentifier = null): string
    {
        // Fallback identifier quoting if no protectIdentifier callback provided
        $quoteIdentifier = $protectIdentifier ?? function(string $identifier) use ($driver): string {
            if (empty($identifier) || $identifier === '*') {
                return $identifier;
            }

            $quoteChar = $driver === 'mysql' ? '`' : '"';
            $identifier = str_replace($quoteChar, $quoteChar . $quoteChar, $identifier);

            // Handle qualified identifiers (table.column)
            $table = strstr($identifier, '.', true); // Get part before dot for quoting
            if ($table !== false) {
                $identifier = substr($identifier, strlen($table) + 1); // Get part after dot
                $table = str_replace($quoteChar, $quoteChar . $quoteChar, $table);
                return $quoteChar . $table . $quoteChar . '.' . $quoteChar . $identifier . $quoteChar;
            }

            return $quoteChar . $identifier . $quoteChar;
        };

        $sql = '';

        switch ($this->type) {
            case static::TYPE_COLUMN:
                $sql = $quoteIdentifier($this->value);
                break;

                // Parameter reference - emit :name directly, referencing a named manual binding
            case static::TYPE_PARAM:
                $sql = ':' . $this->value;
                break;

                // Raw SQL - pass through as-is
            case static::TYPE_RAW:
                $sql = $this->value;
                break;

                // Function call - serialize arguments in literal mode by default
            case static::TYPE_FUNC:
                $sql = $this->value;
                $sql .= '(';
                $sep = '';
                foreach ($this->args as $arg) {
                    $sql .= $sep;
                    $sep = ', ';
                    if ($arg instanceof static) {
                        $sql .= $arg->toSql(
                            $driver,
                            $serialize,
                            $protectIdentifier
                        );
                    } else {
                        $sql .= $serialize($arg);
                    }
                }
                $sql .= ')';
                break;

                // Type cast - driver-specific syntax
            case static::TYPE_CAST:
                if ($this->value instanceof static) {
                    $expr = $this->value->toSql(
                        $driver,
                        $serialize,
                        $protectIdentifier
                    );
                } else {
                    $expr = $serialize($this->value);
                }
                if ($driver === 'pgsql') {
                    $sql = "$expr::{$this->cast}";
                } else {
                    $sql = "CAST($expr AS {$this->cast})";
                }
                break;

                // PostgreSQL array literal
            case static::TYPE_PG_ARRAY:
                $sql = $this->serializePgArray();
                break;

                // Comma-separated list (for IN clauses)
            case static::TYPE_CS_LIST:
                $sql = '';
                $sep = '';
                foreach ($this->value as $v) {
                    $sql .= $sep;
                    $sep = ', ';
                    if ($v instanceof static) {
                        $sql .= $v->toSql(
                            $driver,
                            $serialize,
                            $protectIdentifier
                        );
                    } else {
                        $sql .= $serialize($v);
                    }
                }
                break;

            case static::TYPE_JSON:
                $sql = $serialize(json_encode($this->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                break;

                // String concatenation - driver-specific
            case static::TYPE_CONCAT:
                $serialized = [];
                foreach ($this->value as $p) {
                    if ($p instanceof static) {
                        $serialized[] = $p->toSql($driver, $serialize, $protectIdentifier);
                    } else {
                        $serialized[] = $serialize($p);
                    }
                }
                if ($driver === 'mysql') {
                    $sql = 'CONCAT(';
                    $sql .= \implode(', ', $serialized);
                    $sql .= ')';
                } else {
                    // PostgreSQL and SQLite use || operator
                    $sql = \implode(' || ', $serialized);
                }
                break;

                // Composite expression - concatenate with spaces
                // Treat plain strings as raw SQL tokens for cleaner expressions
            case static::TYPE_EXPR:
                $sql = '';
                $sep = '';
                foreach ($this->value as $p) {
                    $sql .= $sep;
                    $sep = ' ';
                    if ($p instanceof static) {
                        $sql .= $p->toSql($driver, $serialize, $protectIdentifier);
                    } else {
                        $sql .= \is_string($p) ? $p : $serialize($p);
                    }
                }
                break;

                // Literal value - serialize using provided callback
            case static::TYPE_VALUE:
                $sql = $serialize($this->value);
                break;

            case static::TYPE_SUBQUERY:
                // Subquery - wrap in parentheses
                $sql = '(' . $this->value->toSql() . ')';
                break;

            default:
                throw new \LogicException("Unknown Sql node type: {$this->type}");
        }

        // Append alias if set
        if ($this->alias !== null) {
            $sql .= ' AS ';
            $sql .= $quoteIdentifier($this->alias);
        }

        return $sql;
    }

    public function __toString(): string
    {
        // Default to literal serialization for debug-friendly output
        return $this->toSql(
            'debug',
            fn($v) => (string) $v
        );
    }
}

/**
 * Fluent builder for CASE expressions
 */
class SqlCase
{
    protected array $whenClauses = [];
    protected $elseValue = null;

    /**
     * Add WHEN condition THEN result clause
     * @param mixed $condition Condition (scalar or Sql instance)
     * @param mixed $then Result value (scalar or Sql instance)
     * @return static
     */
    public function when($condition, $then): static
    {
        $this->whenClauses[] = ['condition' => $condition, 'then' => $then];
        return $this;
    }

    /**
     * Set ELSE default value
     * @param mixed $value Default value (scalar or Sql instance)
     * @return static
     */
    public function else($value): static
    {
        $this->elseValue = $value;
        return $this;
    }

    /**
     * Finalize and return CASE expression as Sql
     * @return Sql
     */
    public function end(): Sql
    {
        $parts = ['CASE'];

        foreach ($this->whenClauses as $when) {
            $parts[] = 'WHEN';
            $parts[] = $when['condition'] instanceof Sql
                ? $when['condition']
                : Sql::value($when['condition']);
            $parts[] = 'THEN';
            $parts[] = $when['then'] instanceof Sql
                ? $when['then'] :
                Sql::value($when['then']);
        }

        if ($this->elseValue !== null) {
            $parts[] = 'ELSE';
            $parts[] = $this->elseValue instanceof Sql
                ? $this->elseValue :
                Sql::value($this->elseValue);
        }

        $parts[] = 'END';

        return Sql::expr(...$parts);
    }
}