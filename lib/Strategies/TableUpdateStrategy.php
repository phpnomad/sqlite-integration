<?php

namespace PHPNomad\SQLite\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableUpdateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as CoreTableUpdateStrategy;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

/**
 * SQLite TableUpdateStrategy.
 *
 * SQLite's ALTER TABLE has historically been the most limited part of the
 * dialect. The features matter to us:
 *  - ADD COLUMN: supported (since forever). Fast path used here for the
 *    common "I added a field to my entity" case.
 *  - DROP COLUMN: supported since SQLite 3.35 (March 2021). PHP+pdo_sqlite
 *    bundled today is universally >= 3.35, so we use the native form.
 *  - RENAME COLUMN: supported since 3.25. Not exercised by phpnomad/db's
 *    sync flow (the abstraction never renames; it adds + drops).
 *  - MODIFY/CHANGE COLUMN type: NOT supported, ever. SQLite expects you to
 *    create a new table, copy data, drop the old, rename the new. That
 *    pattern is also how you'd handle constraint changes. We detect this
 *    case and perform the dance.
 *
 * For now we only emit type-change migrations when the column's type
 * affinity actually changed — same-affinity changes (e.g. VARCHAR→TEXT)
 * are no-ops in SQLite and don't need a rebuild.
 */
class TableUpdateStrategy implements CoreTableUpdateStrategy
{
    public function __construct(protected DatabaseStrategy $db)
    {
    }

    public function syncColumns(Table $table): void
    {
        try {
            $current = $this->getCurrentColumns($table->getName());
            $target = $table->getColumns();

            if ($this->requiresRebuild($current, $target)) {
                $this->rebuildTable($table, $current);
                return;
            }

            $alters = $this->buildSimpleAlters($table, $current, $target);
            foreach ($alters as $sql) {
                $this->db->query($sql);
            }
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    /**
     * Pull current column shape from PRAGMA table_info().
     *
     * @return array<string, array{name: string, type: string, notnull: int, dflt_value: mixed, pk: int}>
     */
    protected function getCurrentColumns(string $tableName): array
    {
        $sql = $this->db->parse("PRAGMA table_info(?n)", $tableName);
        $rows = $this->db->query($sql);

        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = [
                'name' => $name,
                'type' => (string) ($row['type'] ?? ''),
                'notnull' => (int) ($row['notnull'] ?? 0),
                'dflt_value' => $row['dflt_value'] ?? null,
                'pk' => (int) ($row['pk'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $current
     * @param array<int, Column> $target
     */
    protected function requiresRebuild(array $current, array $target): bool
    {
        // The simple ADD/DROP path can't handle type changes — those need
        // the rebuild dance. Detect any column whose affinity changed.
        foreach ($target as $column) {
            $name = $column->getName();
            if (! isset($current[$name])) {
                continue;
            }
            $oldAffinity = $this->typeAffinity($current[$name]['type']);
            $newAffinity = $this->typeAffinity($this->normalizeType($column->getType()));
            if ($oldAffinity !== $newAffinity) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $current
     * @param array<int, Column> $target
     * @return array<int, string>
     */
    protected function buildSimpleAlters(Table $table, array $current, array $target): array
    {
        $queries = [];
        $targetNames = Arr::pluck($target, 'name');

        foreach ($target as $column) {
            if (! isset($current[$column->getName()])) {
                $queries[] = $this->db->parse(
                    "ALTER TABLE ?n ADD COLUMN " . $this->renderColumn($column),
                    $table->getName()
                );
            }
        }

        foreach (array_keys($current) as $columnName) {
            if (! in_array($columnName, $targetNames, true)) {
                $queries[] = $this->db->parse(
                    "ALTER TABLE ?n DROP COLUMN ?n",
                    $table->getName(),
                    $columnName
                );
            }
        }

        return $queries;
    }

    /**
     * Rebuild the table to match the target shape exactly.
     *
     * Procedure:
     *   1. Create a temp table with the new schema.
     *   2. Copy data from the old table to the temp, mapping shared columns.
     *   3. Drop the old table.
     *   4. Rename the temp to the original name.
     *
     * Wrapped in a transaction so failures roll back cleanly.
     *
     * @param array<string, array<string, mixed>> $currentColumns
     */
    protected function rebuildTable(Table $table, array $currentColumns): void
    {
        $original = $table->getName();
        $temp = $original . '__rebuild_tmp';

        $this->db->query('BEGIN');
        try {
            // 1. Create the temp with the new shape.
            $columnSql = Arr::process($table->getColumns())
                ->map(fn (Column $c) => $this->renderColumn($c))
                ->setSeparator(",\n ")
                ->toString();

            $this->db->query($this->db->parse(
                "CREATE TABLE ?n (\n {$columnSql}\n)",
                $temp
            ));

            // 2. Copy data for columns present in both. Order matters for
            //    INSERT; we use the same order in both clauses.
            $sharedColumns = array_intersect(
                Arr::pluck($table->getColumns(), 'name'),
                array_keys($currentColumns)
            );
            if (! empty($sharedColumns)) {
                $columnList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $sharedColumns));
                $this->db->query($this->db->parse(
                    "INSERT INTO ?n ({$columnList}) SELECT {$columnList} FROM ?n",
                    $temp,
                    $original
                ));
            }

            // 3. Drop the old.
            $this->db->query($this->db->parse("DROP TABLE ?n", $original));

            // 4. Rename the temp into place.
            $this->db->query($this->db->parse(
                "ALTER TABLE ?n RENAME TO ?n",
                $temp,
                $original
            ));

            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    protected function renderColumn(Column $column): string
    {
        $type = $this->normalizeType($column->getType());
        if ($args = $column->getTypeArgs()) {
            $type .= '(' . implode(',', $args) . ')';
        }

        $attrs = [];
        $isPk = false;
        foreach ($column->getAttributes() as $attr) {
            if ($attr === null || $attr === '') {
                continue;
            }
            $upper = strtoupper($attr);
            if ($upper === 'AUTO_INCREMENT' || $upper === 'AUTOINCREMENT') {
                continue;
            }
            if (str_contains($upper, 'PRIMARY KEY')) {
                $isPk = true;
            }
            $attrs[] = $attr;
        }

        if ($isPk) {
            foreach ($column->getAttributes() as $attr) {
                $upper = strtoupper((string) $attr);
                if (str_contains($upper, 'AUTO_INCREMENT') || str_contains($upper, 'AUTOINCREMENT')) {
                    $attrs[] = 'AUTOINCREMENT';
                    break;
                }
            }
        }

        return "\"{$column->getName()}\" {$type} " . implode(' ', $attrs);
    }

    private function normalizeType(string $type): string
    {
        $upper = strtoupper($type);
        return match (true) {
            in_array($upper, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'INT'], true) => 'INTEGER',
            in_array($upper, ['VARCHAR', 'CHAR', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT'], true) => 'TEXT',
            in_array($upper, ['FLOAT', 'DOUBLE', 'DECIMAL'], true) => 'REAL',
            in_array($upper, ['TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB'], true) => 'BLOB',
            $upper === 'DATETIME' || $upper === 'TIMESTAMP' => 'TEXT',
            $upper === 'BOOLEAN' || $upper === 'BOOL' => 'INTEGER',
            default => $type,
        };
    }

    /**
     * SQLite type affinity rules per the docs.
     * https://www.sqlite.org/datatype3.html#determination_of_column_affinity
     */
    private function typeAffinity(string $type): string
    {
        $upper = strtoupper($type);
        if (str_contains($upper, 'INT')) return 'INTEGER';
        if (str_contains($upper, 'CHAR') || str_contains($upper, 'CLOB') || str_contains($upper, 'TEXT')) return 'TEXT';
        if (str_contains($upper, 'BLOB') || $upper === '') return 'BLOB';
        if (str_contains($upper, 'REAL') || str_contains($upper, 'FLOA') || str_contains($upper, 'DOUB')) return 'REAL';
        return 'NUMERIC';
    }
}
