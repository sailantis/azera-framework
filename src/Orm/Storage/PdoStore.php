<?php

namespace Azera\Orm\Storage;

use Azera\AppContext;
use Azera\Db\Database;
use Azera\Db\DatabaseManager;
use Azera\Orm\ConflictTarget;
use Azera\Orm\Metadata;

/**
 * SQL backend of the {@see Store} seam over a {@see Database}.
 *
 * BORROWS, never owns: holds read/write ROLE STRINGS and resolves the live
 * connection via DatabaseManager per operation â€” the same pattern as
 * Model::readConnection(). Consequences: QB + legacy save() + ORM flush all
 * share ONE connection per role (transactions join the caller's nesting);
 * per-call role resolution keeps dynamic tenancy; cost = one cached array
 * read. ALL SQL goes through the connection so Db events fire (tracking).
 *
 * Holds the RETURNING matrix: pk_set -> plain INSERT; all non-PK cols set +
 * driver RETURNING -> RETURNING id; unset non-PK cols -> RETURNING *;
 * no-RETURNING driver -> lastInsertId.
 *
 * Connection-role resolution is PER CLASS: metadata readRole/writeRole
 * (compiled from #[Connection(read|write|role)]) override the constructor
 * defaults, so one shared store instance can route individual classes to
 * dedicated connections. Transactions are PER CONNECTION TARGET: begin($meta)
 * opens a tx on $meta's resolved write connection and records it in the tx
 * map — operations resolving to the SAME Database join it (reads inside the
 * tx see its uncommitted writes), operations resolving to OTHER connections
 * route to their own class's roles. One store instance therefore holds one
 * tx PER DISTINCT write target (flushAll() commits them independently);
 * legacy Model/QB writes on an already-begun target join via the shared
 * Database instance.
 */
final class PdoStore implements Store
{
    /**
     * Transactions THIS store began, keyed by the resolved Database instance
     * (spl_object_id). One store instance holds one tx PER DISTINCT
     * connection target — two classes with different #[Connection(write:)]
     * roles run independent txs side by side under flushAll(). Ownership, not
     * presence: caller-opened txs on the same connections are JOINED by
     * routing but never appear here, so commit()/rollback() never touch them.
     */
    private array $txs = [];

    public function __construct(
        private ?DatabaseManager $dbm = null,
        private string $readRole = 'read',
        private string $writeRole = 'write',
    ) {
        $this->dbm ??= AppContext::instance()->dbManager();
    }

    /**
     * Connection identity for tx grouping in flush(): the class's write
     * role (#[Connection] override wins over the constructor default) —
     * two classes sharing a write role share one transaction target.
     */
    public function txTarget(array $meta): string
    {
        return 'sql:' . ($meta['writeRole'] ?? $this->writeRole);
    }

    /* ------------------------------------------------- connections */

    /**
     * Connection for a READ on $meta's class: metadata readRole
     * (#[Connection]) overrides the constructor default. Reads resolving
     * to a connection with an open tx (this store's or a caller's) run on
     * it and see its uncommitted writes; reads resolving to other
     * connections run autocommit — a tx pins only its own target.
     */
    private function readDb(array $meta): Database
    {
        return $this->dbm->getOrDefault($meta['readRole'] ?? $this->readRole);
    }

    /**
     * Connection for a WRITE on $meta's class: metadata writeRole
     * (#[Connection]) overrides the constructor default. A tx begun on
     * this exact connection (store- or caller-begun) is shared — same
     * Database object — so same-target legacy ops join the flush tx.
     */
    private function writeDb(array $meta): Database
    {
        return $this->dbm->getOrDefault($meta['writeRole'] ?? $this->writeRole);
    }

    public function insertOne(string $class, array $data): array
    {
        $meta = Metadata::for($class);
        $db   = $this->writeDb($meta);

        // Strategy selection lives here (per-situation, not always RETURNING *).
        $strategy = $this->strategy($meta, $data, $db->supportsReturning());
        $set      = array_filter($data, fn($v) => $v !== null);

        [$sql, $params] = $this->insertSql($meta, $set);

        switch ($strategy) {
            case 'no_pk':
                // Nothing to backfill: the table generates its own identity
                // (or has none). Insert and report no id.
                $db->query($sql, $params);
                return ['row' => null, 'id' => null];

            case 'pk_set':
                $db->query($sql, $params);
                return ['row' => null, 'id' => null];

            case 'returning_id':
                $pk  = $this->pkColumn($meta);
                $row = $db->selectRow(
                    $sql . ' RETURNING ' . $db->quoteIdentifier($pk['name']),
                    $params,
                    \PDO::FETCH_ASSOC
                );
                $idValue = $row[$pk['name']] ?? null;
                return ['row' => null, 'id' => $idValue];

            case 'returning_all':
                $row = $db->selectRow(
                    $sql . ' RETURNING *',
                    $params,
                    \PDO::FETCH_ASSOC
                );
                return ['row' => $row, 'id' => null];

            case 'last_insert_id':
            default:
                $db->query($sql, $params);
                $pk = $this->pkColumn($meta);
                $id = $db->lastInsertId();
                return [
                    'row' => null,
                    'id'  => ($id !== false && $id !== '0' && $id !== '') ? $id : null
                ];
        }
    }

    public function updateOne(string $class, array $data, array $id): array
    {
        $meta = Metadata::for($class);
        $db   = $this->writeDb($meta);

        [$sql, $params] = $this->updateSql($meta, $data, $id);
        $db->query($sql, $params);

        return ['row' => null, 'id' => null];
    }

    public function upsertOne(
        string $class,
        array $data,
        ?ConflictTarget $on = null,
        ?array $updateColumns = null,
    ): array {
        $meta = Metadata::for($class);
        $db   = $this->writeDb($meta);

        // Identity columns: the explicit target, else the PK. Either way the
        // DO UPDATE SET clause never reassigns them (see upsertSql()).
        $targetColumns = $on?->columns ?? $this->pkColumnNames($meta);
        $isTarget = array_flip($targetColumns);

        // The only interesting question left: are columns OUTSIDE the target
        // missing? Their DB defaults round-trip via RETURNING * (mirrors
        // insertOne's returning_all); with everything set, a plain statement
        // is the fastest shape. Non-RETURNING drivers: no backfill, same as
        // insertOne's last_insert_id fallback minus the id (an upsert keeps
        // the caller's key).
        $backfill = false;
        foreach ($meta['columns'] as $col) {
            if (!isset($isTarget[$col['name']]) && ($data[$col['name']] ?? null) === null) {
                $backfill = true;
                break;
            }
        }

        $set = array_filter($data, fn($v) => $v !== null);

        if ($updateColumns !== null) {
            // Restrict the UPDATE payload. An empty list keeps the row
            // untouched on conflict, which upsertSql() renders as DO NOTHING.
            $set = array_intersect_key($set, array_flip($updateColumns));
        }

        [$sql, $params] = $this->upsertSql($meta, $set, $db, $on);

        if ($backfill && $db->supportsReturning()) {
            $row = $db->selectRow($sql . ' RETURNING *', $params, \PDO::FETCH_ASSOC);
            return ['row' => $row, 'id' => null];
        }

        $db->query($sql, $params);
        return ['row' => null, 'id' => null];
    }

    public function deleteOne(string $class, array $id): void
    {
        $meta = Metadata::for($class);
        $db   = $this->writeDb($meta);
        [$sql, $params] = $this->deleteSql($meta, $id);
        $db->query($sql, $params);
    }

    public function findBy(string $class, array $where): array
    {
        $meta = Metadata::for($class);
        $db   = $this->readDb($meta);
        [$sql, $params] = $this->selectSql($meta, $where);
        $rows = $db->selectAll($sql, $params, \PDO::FETCH_ASSOC);
        return $rows;
    }

    public function findByPk(string $class, array $id): ?array
    {
        $rows = $this->findBy($class, $id);
        return $rows[0] ?? null;
    }

    public function count(string $class, array $where = []): int
    {
        $meta = Metadata::for($class);
        $db   = $this->readDb($meta);
        [$sql, $params] = $this->countSql($meta, $where);
        $row = $db->selectRow($sql, $params, \PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * begin($meta) opens a transaction on $meta's write connection (the
     * metadata writeRole override wins over the constructor default;
     * null meta = constructor default). Idempotent per connection: an
     * ALREADY-HELD tx on the same Database joins (no savepoint — two
     * write roles aliasing one connection share ONE tx, one BEGIN in the
     * log). Caller-opened txs are never recorded here: they are joined
     * implicitly by routing and never committed/rolled back by this store.
     */
    public function begin(?array $meta = null): void
    {
        $db  = $this->writeDb($meta ?? []);
        $key = spl_object_id($db);

        if (isset($this->txs[$key])) {
            return; // this store already began a tx on that connection
        }

        if ($db->inTransaction()) {
            return; // caller-opened tx: joined by routing, never owned
        }

        $db->begin();
        $this->txs[$key] = $db;
    }

    public function commit(?array $meta = null): void
    {
        foreach ($this->ownedTxs($meta) as $db) {
            $db->commit();
            unset($this->txs[spl_object_id($db)]);
        }
    }

    public function rollback(?array $meta = null): void
    {
        foreach ($this->ownedTxs($meta) as $db) {
            $db->rollback();
            unset($this->txs[spl_object_id($db)]);
        }
    }

    /**
     * $meta form: whether a tx is active on $meta's write connection —
     * store-begun OR caller-opened (flush()/flushAll() join either, and
     * must not double-begin over a caller tx). Bare form: any tx this
     * store began, else the constructor-default write connection.
     */
    public function inTransaction(?array $meta = null): bool
    {
        if ($meta !== null) {
            return $this->writeDb($meta)->inTransaction();
        }

        foreach ($this->txs as $db) {
            if ($db->inTransaction()) {
                return true;
            }
        }

        return $this->dbm->getOrDefault($this->writeRole)->inTransaction();
    }

    /**
     * The map-held txs commit()/rollback() act on: with meta, exactly the
     * one on $meta's write connection (absent = no-op — never a
     * caller-opened tx); bare, EVERY tx this store began.
     *
     * @return list<Database>
     */
    private function ownedTxs(?array $meta): array
    {
        if ($meta === null) {
            return array_values($this->txs);
        }

        $db  = $this->writeDb($meta);
        $key = spl_object_id($db);

        return isset($this->txs[$key]) ? [$db] : [];
    }

    /* -------------------------------------------------- helpers */

    /**
     * RETURNING matrix (per-situation, NOT always RETURNING *):
     * pk_set -> plain INSERT; all non-PK cols set + driver RETURNING ->
     * RETURNING id; unset non-PK cols -> RETURNING *; no-RETURNING driver
     * -> lastInsertId.
     */
    /**
     * Pick the INSERT's backfill strategy.
     *
     * A class with NO key (`pkFields === []`) has nothing to backfill, so the
     * strategy is a plain INSERT with no RETURNING and no `lastInsertId()`.
     * Historically this branch returned 'last_insert_id', which then called
     * pkColumn() and threw mid-flush — AFTER the INSERT had already run, so
     * a batch could commit half its rows and report an error.
     */
    private function strategy(array $meta, array $data, bool $supportsReturning): string
    {
        $pkCols = array_values(array_filter($meta['columns'], fn($c) => $c['pk']));
        if ($pkCols === []) {
            return 'no_pk';
        }

        $pkSet = true;
        foreach ($pkCols as $col) {
            if (($data[$col['name']] ?? null) === null) {
                $pkSet = false;
                break;
            }
        }

        if ($pkSet) {
            return 'pk_set';
        }

        if (!$supportsReturning) {
            return 'last_insert_id';
        }

        $nonPkNames = array_map(fn($c) => $c['name'], array_filter($meta['columns'], fn($c) => !$c['pk']));
        $missing    = array_diff($nonPkNames, array_keys(array_filter($data, fn($v) => $v !== null)));

        if ($missing !== []) {
            return 'returning_all';
        }

        // Single PK: RETURNING id is enough. Composite PK: every part
        // must backfill (RETURNING id would leave the other parts unset).
        return \count($pkCols) === 1 ? 'returning_id' : 'returning_all';
    }

    private function pkColumn(array $meta): array
    {
        foreach ($meta['columns'] as $col) {
            if ($col['pk']) {
                return $col;
            }
        }

        throw new \RuntimeException("No PK column in metadata for {$meta['class']}");
    }

    /**
     * Schema-qualified quoted table name from metadata
     * (#[Entity(schema)] / schema() override — null schema yields the
     * bare table).
     */
    private function table(Database $db, array $meta): string
    {
        return $db->quoteIdentifier($meta['schema'] ?? null, $meta['source']);
    }

    private function insertSql(array $meta, array $set): array
    {
        $db   = $this->writeDb($meta);
        $cols = array_map(fn($c) => $db->quoteIdentifier($c), array_keys($set));
        $bind = array_fill(0, \count($set), '?');

        return [
            'INSERT INTO ' . $this->table($db, $meta)
                . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $bind) . ')',
            array_values($set),
        ];
    }

    /**
     * Single-statement UPSERT. The conflict target is the caller's
     * {@see ConflictTarget}, or the metadata PK when none was given.
     * The DO UPDATE SET writes NON-TARGET columns only, via excluded refs
     * ("col" = EXCLUDED."col") — the fast shape. Naming a TARGET column in
     * SET (or rebinding values instead of referencing EXCLUDED) forces
     * SQLite to compile the statement as an internal DELETE+INSERT, which
     * is fsync-bound (~1.2-5.7ms vs ~8µs per statement on WAL SQLite), and a
     * partial index's target column cannot be reassigned meaningfully
     * anyway.
     *
     * Driver dialects: pgsql/sqlite use ON CONFLICT (target) [WHERE pred]
     * DO UPDATE; mysql/maria use ON DUPLICATE KEY UPDATE with VALUES() refs
     * (their only form; no conflict target). Null values in $set are
     * dropped upstream (same as insertOne) so DB defaults survive.
     */
    private function upsertSql(array $meta, array $set, Database $db, ?ConflictTarget $on = null): array
    {
        [$insertSql, $params] = $this->insertSql($meta, $set);

        // No key = no conflict target. There is nothing to be "on conflict"
        // about, so the statement degrades to a plain INSERT rather than
        // emitting the invalid `ON CONFLICT ()`.
        $targetColumns = $on?->columns ?? $this->pkColumnNames($meta);
        if ($targetColumns === []) {
            return [$insertSql, $params];
        }

        $driver = $db->getDriver();

        if ($driver === 'mysql') {
            // ON DUPLICATE KEY UPDATE has no target clause: it fires on ANY
            // violated unique index. An explicit target therefore cannot be
            // honoured — and silently updating whichever row happened to
            // collide is worse than failing, because the caller asked for a
            // specific key.
            if ($on !== null) {
                throw new \LogicException(
                    "Cannot upsert on '" . implode(', ', $on->columns) . "' with MySQL: "
                        . 'ON DUPLICATE KEY UPDATE has no conflict target, so it fires on any violated '
                        . 'unique index. Use a database with ON CONFLICT (PostgreSQL or SQLite), '
                        . 'or leave the target unset to conflict on the primary key.'
                );
            }

            // MySQL has no conflict target and no EXCLUDED — VALUES(col) refs.
            $sets = [];
            foreach ($set as $col => $value) {
                if ($this->isPkColumn($meta, $col)) {
                    continue;
                }
                $sets[] = $db->quoteIdentifier($col) . ' = VALUES(' . $db->quoteIdentifier($col) . ')';
            }
            return [$insertSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets), $params];
        }

        // pgsql / sqlite: explicit conflict target + EXCLUDED refs.
        $isTarget = array_flip($targetColumns);

        $sets = [];
        foreach ($set as $col => $value) {
            if (isset($isTarget[$col]) || $this->isPkColumn($meta, $col)) {
                continue;
            }
            $sets[] = $db->quoteIdentifier($col) . ' = EXCLUDED.' . $db->quoteIdentifier($col);
        }

        $target = implode(', ', array_map(fn($c) => $db->quoteIdentifier($c), $targetColumns));

        // A PARTIAL unique index is only inferred when the statement repeats
        // its predicate — the predicate is part of the index's identity, not
        // a filter on the action.
        $clause = ' (' . $target . ')' . ($on?->where !== null ? ' WHERE ' . $on->where : '');

        // Every INSERT column belongs to the target: there is nothing left to
        // assign, and `DO UPDATE SET` with an empty list is a syntax error.
        // The honest statement is "the row already exists, leave it".
        if ($sets === []) {
            return [$insertSql . ' ON CONFLICT' . $clause . ' DO NOTHING', $params];
        }

        return [$insertSql . ' ON CONFLICT' . $clause . ' DO UPDATE SET ' . implode(', ', $sets), $params];
    }

    /**
     * Column names of the metadata PKs, in declaration order.
     *
     * @return list<string>
     */
    private function pkColumnNames(array $meta): array
    {
        $names = [];
        foreach ($meta['columns'] as $col) {
            if ($col['pk']) {
                $names[] = $col['name'];
            }
        }

        return $names;
    }

    private function isPkColumn(array $meta, string $colName): bool
    {
        foreach ($meta['columns'] as $col) {
            if ($col['pk'] && $col['name'] === $colName) {
                return true;
            }
        }

        return false;
    }

    private function updateSql(array $meta, array $data, array $id): array
    {
        $db   = $this->writeDb($meta);
        $sets = [];
        $bind = [];

        foreach ($data as $col => $value) {
            $sets[] = $db->quoteIdentifier($col) . ' = ?';
            $bind[] = $value;
        }

        $wheres = [];
        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk'] && isset($id[$field])) {
                $wheres[] = $db->quoteIdentifier($col['name']) . ' = ?';
                $bind[] = $id[$field];
            }
        }

        return [
            'UPDATE ' . $this->table($db, $meta)
                . ' SET ' . implode(', ', $sets)
                . ' WHERE ' . implode(' AND ', $wheres ?: ['1=0']),
            $bind,
        ];
    }

    private function deleteSql(array $meta, array $id): array
    {
        $db     = $this->writeDb($meta);
        $wheres = [];
        $bind   = [];

        foreach ($meta['columns'] as $field => $col) {
            if ($col['pk'] && isset($id[$field])) {
                $wheres[] = $db->quoteIdentifier($col['name']) . ' = ?';
                $bind[] = $id[$field];
            }
        }

        return [
            'DELETE FROM ' . $this->table($db, $meta)
                . ' WHERE ' . implode(' AND ', $wheres ?: ['1=0']),
            $bind,
        ];
    }

    private function selectSql(array $meta, array $where): array
    {
        $db     = $this->readDb($meta);
        $wheres = [];
        $bind   = [];

        foreach ($where as $field => $value) {
            $colName = $meta['columns'][$field]['name'] ?? $field;
            $wheres[] = $db->quoteIdentifier($colName) . ' = ?';
            $bind[] = $value;
        }

        $sql = 'SELECT * FROM ' . $this->table($db, $meta)
            . ($wheres === [] ? '' : ' WHERE ' . implode(' AND ', $wheres));

        return [$sql, $bind];
    }

    private function countSql(array $meta, array $where): array
    {
        [$sql, $bind] = $this->selectSql($meta, $where);
        $sql = str_replace('SELECT *', 'SELECT COUNT(*) AS cnt', $sql);

        return [$sql, $bind];
    }

    public function enrichMetadata(array $meta, \ReflectionClass $class): array
    {
        return $meta;
    }
}