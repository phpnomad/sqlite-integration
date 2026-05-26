<?php

namespace PHPNomad\SQLite\Integration\Interfaces;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

/**
 * SQLite-side analog of phpnomad/mysql-integration's DatabaseStrategy.
 *
 * `parse()` interpolates the same `?n`, `?s`, `?a`, `?i`, `?d` placeholders
 * the rest of the phpnomad/db world uses, so QueryBuilder and ClauseBuilder
 * code is portable between the two dialects.
 *
 * `query()` executes the parsed SQL and returns rows for SELECT or an empty
 * array for everything else.
 */
interface DatabaseStrategy
{
    /**
     * Parse a query template with safemysql-style placeholders into safe SQL.
     *
     * Placeholders:
     *  - ?n  identifier (table/column name) — quoted with "
     *  - ?s  string value — single-quoted, escaped
     *  - ?i  integer value
     *  - ?d  double/float value
     *  - ?a  array of values — comma-separated, scalar-typed (for IN/NOT IN)
     */
    public function parse(string $query, ...$args): string;

    /**
     * Execute a parsed query.
     *
     * @return array<int, array<string, mixed>> Rows (empty for non-SELECT).
     * @throws DatastoreErrorException
     */
    public function query(string $query): array;

    /**
     * Returns the integer rowid of the last INSERT.
     */
    public function lastInsertId(): int;
}
