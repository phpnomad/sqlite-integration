<?php

namespace PHPNomad\SQLite\Integration;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Single-connection PDO wrapper around a SQLite database file. The DI
 * container binds this as a singleton via factory; tests inject :memory:.
 *
 * SQLite-specific connection tweaks live here so the strategy classes
 * stay focused on query shape, not driver setup.
 */
class Connection
{
    private PDO $pdo;

    public function __construct(string $dsn = 'sqlite::memory:')
    {
        try {
            $this->pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("Failed to open SQLite database [{$dsn}]: {$e->getMessage()}", 0, $e);
        }

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
