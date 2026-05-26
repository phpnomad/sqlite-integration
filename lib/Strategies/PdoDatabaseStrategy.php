<?php

namespace PHPNomad\SQLite\Integration\Strategies;

use PDOException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\SQLite\Integration\Connection;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;

/**
 * PDO+SQLite implementation. parse() builds a fully-interpolated SQL string
 * with values escaped via the underlying PDO::quote (so we delegate the
 * actual escaping to the driver, not a hand-rolled regex).
 */
class PdoDatabaseStrategy implements DatabaseStrategy
{
    public function __construct(protected Connection $connection)
    {
    }

    public function parse(string $query, ...$args): string
    {
        $pdo = $this->connection->pdo();
        $index = 0;

        // Walk placeholders left-to-right. We pop arguments in order rather
        // than indexing because ?a expands to N comma-separated values from
        // a single arg, breaking 1:1 correspondence with the source token.
        return preg_replace_callback(
            '/\?[nsiad]/',
            function (array $match) use (&$index, $args, $pdo): string {
                if (! array_key_exists($index, $args)) {
                    throw new \InvalidArgumentException(
                        "Missing argument for placeholder {$match[0]} (index {$index})"
                    );
                }
                $value = $args[$index++];

                return match ($match[0]) {
                    '?n' => $this->quoteIdentifier((string) $value),
                    '?s' => $value === null ? 'NULL' : $pdo->quote((string) $value),
                    '?i' => $value === null ? 'NULL' : (string) (int) $value,
                    '?d' => $value === null ? 'NULL' : (string) (float) $value,
                    '?a' => $this->quoteArray((array) $value),
                };
            },
            $query
        );
    }

    public function query(string $query): array
    {
        try {
            $stmt = $this->connection->pdo()->query($query);
            if ($stmt === false) {
                return [];
            }

            // SQLite's PDO returns false for non-SELECT statements when you
            // call fetchAll(); guard so callers always get an array.
            if ($stmt->columnCount() === 0) {
                return [];
            }

            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            throw new DatastoreErrorException(
                "SQLite query failed: {$e->getMessage()}\n--\n{$query}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * Quote a SQLite identifier with double quotes. Doubles any embedded
     * double quotes per the SQLite spec.
     */
    private function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * @param array<int, mixed> $values
     */
    private function quoteArray(array $values): string
    {
        $pdo = $this->connection->pdo();
        $quoted = [];
        foreach ($values as $value) {
            if ($value === null) {
                $quoted[] = 'NULL';
            } elseif (is_int($value) || is_float($value)) {
                $quoted[] = (string) $value;
            } else {
                $quoted[] = $pdo->quote((string) $value);
            }
        }
        return '(' . implode(',', $quoted) . ')';
    }
}
