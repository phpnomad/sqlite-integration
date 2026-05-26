<?php

namespace PHPNomad\SQLite\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableCreateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategy;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

/**
 * SQLite TableCreateStrategy.
 *
 * Differences from MySQL:
 *  - No `CHARACTER SET` / `COLLATE` table-level clauses. SQLite is always
 *    UTF-8 for TEXT; per-column COLLATE NOCASE is honored when emitted.
 *  - No `AUTO_INCREMENT` keyword. Auto-id is a property of `INTEGER PRIMARY KEY`
 *    columns; we translate any MySQL-style `AUTO_INCREMENT` attribute into
 *    SQLite's `AUTOINCREMENT` post-fixing on a primary key column.
 *  - Type tokens are massaged: SQLite cares about type *affinity* rather
 *    than exact spelling, but apps will pass MySQL-flavored types
 *    (`VARCHAR(255)`, `BIGINT`). We map a small set to their SQLite-canonical
 *    equivalents so the resulting schemas behave sanely.
 */
class TableCreateStrategy implements CoreTableCreateStrategy
{
    public function __construct(protected DatabaseStrategy $db)
    {
    }

    public function create(Table $table): void
    {
        try {
            $this->db->query($this->buildCreateQuery($table));

            // Indices in MySQL live inside the CREATE TABLE statement;
            // SQLite makes them separate `CREATE INDEX` statements.
            foreach ($this->buildIndexQueries($table) as $indexQuery) {
                $this->db->query($indexQuery);
            }
        } catch (\Exception $e) {
            throw new TableCreateFailedException($e);
        }
    }

    protected function buildCreateQuery(Table $table): string
    {
        $columnSql = $this->convertColumnsToSqlString($table);

        return $this->db->parse(
            "CREATE TABLE IF NOT EXISTS ?n (\n {$columnSql}\n);",
            $table->getName()
        );
    }

    /**
     * @return array<int, string>
     */
    protected function buildIndexQueries(Table $table): array
    {
        $queries = [];
        foreach ($table->getIndices() as $index) {
            $sql = $this->convertIndexToCreateIndex($table, $index);
            if ($sql !== null) {
                $queries[] = $sql;
            }
        }
        return $queries;
    }

    protected function convertColumnsToSqlString(Table $table): string
    {
        return Arr::process($table->getColumns())
            ->map(fn (Column $column) => $this->convertColumnToSchemaString($column))
            ->setSeparator(",\n ")
            ->toString();
    }

    /**
     * Render a column as SQLite DDL. Attributes are filtered for SQLite-
     * compatible ones; AUTO_INCREMENT becomes AUTOINCREMENT on PK columns.
     */
    protected function convertColumnToSchemaString(Column $column): string
    {
        $type = $this->translateType($column->getType());

        if ($args = $column->getTypeArgs()) {
            $type .= '(' . implode(',', $args) . ')';
        }

        $attrs = [];
        $isPrimary = false;
        foreach ($column->getAttributes() as $attr) {
            if ($attr === '') {
                continue;
            }
            $upper = strtoupper($attr);
            if ($upper === 'AUTO_INCREMENT' || $upper === 'AUTOINCREMENT') {
                // Will append AUTOINCREMENT after PRIMARY KEY token below.
                continue;
            }
            if (str_contains($upper, 'PRIMARY KEY')) {
                $isPrimary = true;
            }
            if (str_contains($upper, 'CHARACTER SET') || str_contains($upper, 'COLLATE UTF8')) {
                // MySQL-specific encoding hints. SQLite is UTF-8 by default.
                continue;
            }
            $attrs[] = $attr;
        }

        if ($this->wasAutoIncrement($column) && $isPrimary) {
            $attrs[] = 'AUTOINCREMENT';
        }

        return "\"{$column->getName()}\" {$type} " . implode(' ', $attrs);
    }

    protected function convertIndexToCreateIndex(Table $table, Index $index): ?string
    {
        $cols = $index->getColumns();
        if (empty($cols)) {
            return null;
        }

        $type = strtoupper($index->getType() ?? '');
        if (str_contains($type, 'PRIMARY')) {
            // Primary keys are emitted inline on the column, not as a
            // separate index statement.
            return null;
        }

        $unique = str_contains($type, 'UNIQUE') ? 'UNIQUE ' : '';
        $name = $index->getName() ?: ($table->getName() . '_' . implode('_', $cols) . '_idx');
        $columnList = implode(', ', array_map(fn (string $c) => '"' . $c . '"', $cols));

        return $this->db->parse(
            "CREATE {$unique}INDEX IF NOT EXISTS ?n ON ?n ({$columnList})",
            $name,
            $table->getName()
        );
    }

    private function wasAutoIncrement(Column $column): bool
    {
        foreach ($column->getAttributes() as $attr) {
            $upper = strtoupper((string) $attr);
            if (str_contains($upper, 'AUTO_INCREMENT') || str_contains($upper, 'AUTOINCREMENT')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map MySQL-flavored types to SQLite-canonical ones. SQLite is loose
     * about types in storage (type affinity) but DDL still parses tokens,
     * so we normalize for clarity and to avoid surprises with date funcs
     * that key off type affinity.
     */
    private function translateType(string $type): string
    {
        $upper = strtoupper($type);
        return match (true) {
            in_array($upper, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT'], true) => 'INTEGER',
            $upper === 'INT' => 'INTEGER',
            in_array($upper, ['VARCHAR', 'CHAR', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT'], true) => 'TEXT',
            in_array($upper, ['FLOAT', 'DOUBLE', 'DECIMAL'], true) => 'REAL',
            in_array($upper, ['TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB'], true) => 'BLOB',
            $upper === 'DATETIME' || $upper === 'TIMESTAMP' => 'TEXT',
            $upper === 'BOOLEAN' || $upper === 'BOOL' => 'INTEGER',
            default => $type,
        };
    }
}
