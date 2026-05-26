<?php

namespace PHPNomad\SQLite\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableDropFailedException;
use PHPNomad\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategy;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;

class TableDeleteStrategy implements CoreTableDeleteStrategy
{
    public function __construct(protected DatabaseStrategy $db)
    {
    }

    public function delete(string $tableName): void
    {
        try {
            $query = $this->db->parse("DROP TABLE IF EXISTS ?n", $tableName);
            $this->db->query($query);
        } catch (\Exception $e) {
            throw new TableDropFailedException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
