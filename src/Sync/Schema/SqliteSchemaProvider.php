<?php
namespace Azera\Sync\Schema;

use PDO;

class SqliteSchemaProvider implements SchemaProvider
{
    public function __construct(private PDO $pdo) {}

    public function listTables(?string $schema = null): array
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table'"
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getTableSchema(string $table, ?string $schema = null): TableSchema
    {
        return new TableSchema(
            $table,
            null,
            $this->loadColumns($table),
            $this->loadIndexes($table)
        );
    }

    private function loadColumns(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
        $cols = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $cols[] = new ColumnSchema(
                name: $col['name'],
                type: $col['type'],
                nullable: !$col['notnull'],
                default: $col['dflt_value'],
                primary: $col['pk'] == 1,
                comment: null
            );
        }

        return $cols;
    }

    private function loadIndexes(string $table): array
    {
        $stmt    = $this->pdo->query("PRAGMA index_list(" . $this->quoteIdentifier($table) . ")");
        $indexes = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $name   = $idx['name'];
            $unique = $idx['unique'] == 1;

            $stmt2   = $this->pdo->query("PRAGMA index_info(" . $this->quoteIdentifier($name) . ")");
            $columns = array_column($stmt2->fetchAll(PDO::FETCH_ASSOC), 'name');

            $indexes[] = new IndexSchema($name, $unique, $columns);
        }

        return $indexes;
    }

    /**
     * Quote an SQLite identifier for a PRAGMA statement.
     *
     * PRAGMA accepts a quoted identifier or string; doubling embedded quotes
     * prevents a crafted table/index name from breaking out of the literal.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return "'" . str_replace("'", "''", $identifier) . "'";
    }
}