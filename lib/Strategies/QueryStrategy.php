<?php

namespace PHPNomad\SQLite\Integration\Strategies;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

/**
 * SQLite QueryStrategy. Ported from mysql-integration with these dialect
 * differences:
 *
 *  - DELETE/UPDATE don't use the `DELETE alias FROM tbl AS alias` or
 *    `UPDATE tbl AS alias SET ...` forms that MySQL supports. SQLite
 *    operates on the table name directly; clause builders prepend the
 *    table alias for column references but the FROM/UPDATE target is just
 *    the table name.
 *  - Auto-increment identity comes from `last_insert_rowid()`, exposed by
 *    PDO::lastInsertId() — no SELECT round-trip needed.
 *  - Column attributes use `AUTOINCREMENT` (one word) on `INTEGER PRIMARY KEY`.
 */
class QueryStrategy implements CoreQueryStrategy
{
    public function __construct(
        protected DatabaseStrategy $db,
        protected TableSchemaService $tableSchemaService,
        protected ClauseBuilder $clauseBuilder,
    ) {
    }

    /** @inheritDoc */
    public function query(QueryBuilder $builder): array
    {
        try {
            $result = $this->db->query($builder->build());
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get results failed. Invalid query: ' . $e->getMessage(), 500, $e);
        }

        if (empty($result)) {
            throw new RecordNotFoundException();
        }

        return $result;
    }

    /** @inheritDoc */
    public function insert(Table $table, array $data): array
    {
        $columns = Arr::process($data)
            ->keys()
            ->map(fn (string $key) => '?n')
            ->setSeparator(',')
            ->toString();

        $placeholders = Arr::process($data)
            ->map(fn () => '?s')
            ->setSeparator(',')
            ->toString();

        // Interleave column-name args and value args so the ?n/?s sequence
        // lines up: cols first, then values, in source order.
        $columnArgs = array_keys($data);
        $valueArgs = array_values($data);

        $query = $this->db->parse(
            "INSERT INTO ?n ({$columns}) VALUES ({$placeholders})",
            $table->getName(),
            ...$columnArgs,
            ...$valueArgs
        );

        $this->db->query($query);
        return $this->resolveInsertIdentity($table, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws DatastoreErrorException
     */
    protected function resolveInsertIdentity(Table $table, array $data): array
    {
        $identity = [];
        $primaryColumns = $this->tableSchemaService->getPrimaryColumnsForTable($table);

        foreach ($primaryColumns as $column) {
            $name = $column->getName();

            if (array_key_exists($name, $data)) {
                $identity[$name] = $data[$name];
                continue;
            }

            // SQLite's autoincrement column is implicit rowid on
            // `INTEGER PRIMARY KEY` columns; the explicit `AUTOINCREMENT`
            // keyword forces a strictly-increasing rowid. Either way the
            // last id is exposed by PDO::lastInsertId().
            if ($this->isAutoIncrement($column)) {
                $identity[$name] = $this->db->lastInsertId();
            } else {
                throw new DatastoreErrorException(
                    "Missing identity field '{$name}' and it is not auto-increment."
                );
            }
        }

        return $identity;
    }

    /** @inheritDoc */
    public function delete(Table $table, array $ids): void
    {
        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->andWhere($key, '=', $value);
        }

        $whereClause = $this->clauseBuilder->build();

        // SQLite DELETE doesn't accept the multi-table form. The table name
        // alone is the target; column references in the WHERE clause may
        // still be alias-prefixed (e.g. "t.id = 1") because the clause
        // builder prepends, but that only works inside SQLite if the
        // FROM/UPDATE statement declares the alias. We use the bare name
        // form and accept that compound aliased predicates won't apply —
        // mirror the mysql-integration semantics, simpler dialect.
        $query = $this->db->parse(
            "DELETE FROM ?n AS ?n WHERE {$whereClause}",
            $table->getName(),
            $table->getAlias()
        );

        $this->db->query($query);
    }

    /** @inheritDoc */
    public function update(Table $table, array $ids, array $data): void
    {
        $setClause = Arr::process($data)
            ->each(fn ($v, $k) => '?n = ?s')
            ->setSeparator(', ')
            ->toString();

        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->andWhere($key, '=', $value);
        }
        $whereClause = $this->clauseBuilder->build();

        $setBindings = [];
        foreach ($data as $key => $val) {
            $setBindings[] = $key;
            $setBindings[] = $val;
        }

        // SQLite supports table alias in UPDATE since 3.39 — works in any
        // reasonable PHP+SQLite from the last few years.
        $query = $this->db->parse(
            "UPDATE ?n AS ?n SET {$setClause} WHERE {$whereClause}",
            $table->getName(),
            $table->getAlias(),
            ...$setBindings
        );

        $this->db->query($query);
    }

    /** @inheritDoc */
    public function estimatedCount(Table $table): int
    {
        // SQLite has no equivalent of MySQL's `EXPLAIN`/INFORMATION_SCHEMA
        // statistics; `SELECT COUNT(*)` is honest. For large tables, apps
        // should maintain their own counters rather than relying on this.
        $query = $this->db->parse("SELECT COUNT(*) AS cnt FROM ?n", $table->getName());

        try {
            $result = $this->db->query($query);
            return (int) Arr::get($result[0] ?? [], 'cnt', 0);
        } catch (\Exception $e) {
            throw new DatastoreErrorException('Count query failed: ' . $e->getMessage(), 500, $e);
        }
    }

    private function isAutoIncrement(\PHPNomad\Database\Factories\Column $column): bool
    {
        $attributes = array_map('strtoupper', $column->getAttributes());
        foreach ($attributes as $attr) {
            if (str_contains($attr, 'AUTOINCREMENT') || str_contains($attr, 'AUTO_INCREMENT')) {
                return true;
            }
        }
        return false;
    }
}
