<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\SQLite\Integration\Connection;
use PHPNomad\SQLite\Integration\Tests\TestCase;
use RuntimeException;

class ConnectionTest extends TestCase
{
    public function test_memory_dsn_works(): void
    {
        $conn = new Connection('sqlite::memory:');
        $rows = $conn->select('SELECT 1 AS v');
        $this->assertSame(1, (int) $rows[0]['v']);
    }

    public function test_invalid_dsn_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);
        new Connection('not-a-valid-dsn://something');
    }

    public function test_foreign_keys_are_enabled(): void
    {
        $conn = new Connection('sqlite::memory:');
        $rows = $conn->select('PRAGMA foreign_keys');
        // PRAGMA foreign_keys returns column "foreign_keys" with 0 or 1.
        $this->assertSame(1, (int) ($rows[0]['foreign_keys'] ?? 0));
    }

    public function test_journal_mode_is_wal_for_file_dbs(): void
    {
        // :memory: silently ignores journal_mode=WAL and reports "memory".
        // Verify on a real file.
        $file = tempnam(sys_get_temp_dir(), 'sqltest_');
        try {
            $conn = new Connection('sqlite:' . $file);
            $rows = $conn->select('PRAGMA journal_mode');
            $this->assertSame('wal', strtolower($rows[0]['journal_mode']));
        } finally {
            @unlink($file);
            @unlink($file . '-wal');
            @unlink($file . '-shm');
        }
    }

    public function test_execute_returns_affected_row_count(): void
    {
        $conn = new Connection('sqlite::memory:');
        $conn->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->execute("INSERT INTO t (name) VALUES ('a'), ('b'), ('c')");

        $affected = $conn->execute("UPDATE t SET name = 'x'");
        $this->assertSame(3, $affected);
    }

    public function test_select_returns_associative_rows(): void
    {
        $conn = new Connection('sqlite::memory:');
        $conn->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, label TEXT)');
        $conn->execute("INSERT INTO t (label) VALUES ('first'), ('second')");

        $rows = $conn->select('SELECT id, label FROM t ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertSame('first', $rows[0]['label']);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('label', $rows[0]);
    }

    public function test_prepared_parameters_are_safely_bound(): void
    {
        $conn = new Connection('sqlite::memory:');
        $conn->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, content TEXT)');
        $conn->execute(
            'INSERT INTO t (content) VALUES (:content)',
            [':content' => "'; DROP TABLE t; --"]
        );

        $rows = $conn->select('SELECT content FROM t');
        $this->assertCount(1, $rows);
        $this->assertSame("'; DROP TABLE t; --", $rows[0]['content']);
    }

    public function test_last_insert_id_reflects_rowid_growth(): void
    {
        $conn = new Connection('sqlite::memory:');
        $conn->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n TEXT)');
        $conn->execute("INSERT INTO t (n) VALUES ('one')");
        $this->assertSame('1', $conn->lastInsertId());
        $conn->execute("INSERT INTO t (n) VALUES ('two')");
        $this->assertSame('2', $conn->lastInsertId());
    }
}
