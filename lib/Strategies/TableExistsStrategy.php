<?php

namespace PHPNomad\SQLite\Integration\Strategies;

use PHPNomad\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;

class TableExistsStrategy implements CoreTableExistsStrategy
{
    public function __construct(protected DatabaseStrategy $db)
    {
    }

    public function exists(string $tableName): bool
    {
        try {
            // sqlite_master is the system catalog. Older SQLite versions
            // (< 3.33) only had sqlite_master; newer ones added sqlite_schema
            // as a synonym. sqlite_master still works in both.
            $query = $this->db->parse(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?s",
                $tableName
            );
            $rows = $this->db->query($query);
            return ! empty($rows);
        } catch (DatastoreErrorException $e) {
            return false;
        }
    }
}
