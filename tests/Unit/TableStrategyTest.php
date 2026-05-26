<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\Database\Factories\Index;
use PHPNomad\SQLite\Integration\Connection;
use PHPNomad\SQLite\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableDeleteStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableExistsStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableUpdateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;
use PHPNomad\SQLite\Integration\Tests\TestCase;

class TableStrategyTest extends TestCase
{
    private PdoDatabaseStrategy $db;

    protected function setUp(): void
    {
        $this->db = new PdoDatabaseStrategy(new Connection('sqlite::memory:'));
    }

    // ── create / exists / delete ──────────────────────────────────────

    public function test_create_and_exists_and_delete_round_trip(): void
    {
        $table = TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('email', 'VARCHAR', ['NOT NULL'], ['255']),
        ]);

        $exists = new TableExistsStrategy($this->db);
        $create = new TableCreateStrategy($this->db);
        $delete = new TableDeleteStrategy($this->db);

        $this->assertFalse($exists->exists('users'));

        $create->create($table);
        $this->assertTrue($exists->exists('users'));

        $delete->delete('users');
        $this->assertFalse($exists->exists('users'));
    }

    public function test_create_is_idempotent_via_if_not_exists(): void
    {
        $table = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
        ]);
        $create = new TableCreateStrategy($this->db);

        $create->create($table);
        $create->create($table); // shouldn't throw
        $this->assertTrue((new TableExistsStrategy($this->db))->exists('docs'));
    }

    public function test_delete_is_idempotent_via_if_exists(): void
    {
        $delete = new TableDeleteStrategy($this->db);
        $delete->delete('phantom'); // no-op, no throw
        $this->assertFalse((new TableExistsStrategy($this->db))->exists('phantom'));
    }

    public function test_exists_returns_false_for_view_or_index(): void
    {
        $this->db->query('CREATE TABLE base (id INTEGER PRIMARY KEY)');
        $this->db->query('CREATE VIEW v_base AS SELECT * FROM base');
        $this->db->query('CREATE INDEX i_base ON base(id)');

        $exists = new TableExistsStrategy($this->db);
        $this->assertTrue($exists->exists('base'));
        $this->assertFalse($exists->exists('v_base'));
        $this->assertFalse($exists->exists('i_base'));
    }

    // ── type translation ─────────────────────────────────────────────

    public function test_create_translates_mysql_integer_types_to_integer(): void
    {
        $table = TableHelper::table('items', [
            TableHelper::column('a', 'TINYINT'),
            TableHelper::column('b', 'SMALLINT'),
            TableHelper::column('c', 'MEDIUMINT'),
            TableHelper::column('d', 'BIGINT'),
            TableHelper::column('e', 'INT'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);

        $types = $this->columnTypes('items');
        foreach (['a', 'b', 'c', 'd', 'e'] as $col) {
            $this->assertSame('INTEGER', $types[$col], "expected $col to be INTEGER");
        }
    }

    public function test_create_translates_mysql_text_types_to_text(): void
    {
        $table = TableHelper::table('docs', [
            TableHelper::column('a', 'VARCHAR', [], ['255']),
            TableHelper::column('b', 'CHAR', [], ['10']),
            TableHelper::column('c', 'TINYTEXT'),
            TableHelper::column('d', 'MEDIUMTEXT'),
            TableHelper::column('e', 'LONGTEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);

        $types = $this->columnTypes('docs');
        $this->assertStringContainsString('TEXT', $types['a']);
        $this->assertStringContainsString('TEXT', $types['b']);
        $this->assertSame('TEXT', $types['c']);
        $this->assertSame('TEXT', $types['d']);
        $this->assertSame('TEXT', $types['e']);
    }

    public function test_create_translates_mysql_blob_types_to_blob(): void
    {
        $table = TableHelper::table('files', [
            TableHelper::column('a', 'TINYBLOB'),
            TableHelper::column('b', 'MEDIUMBLOB'),
            TableHelper::column('c', 'LONGBLOB'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);
        $types = $this->columnTypes('files');
        foreach (['a', 'b', 'c'] as $col) {
            $this->assertSame('BLOB', $types[$col]);
        }
    }

    public function test_create_translates_datetime_to_text(): void
    {
        $table = TableHelper::table('events', [
            TableHelper::column('at', 'DATETIME'),
            TableHelper::column('then', 'TIMESTAMP'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);
        $types = $this->columnTypes('events');
        $this->assertSame('TEXT', $types['at']);
        $this->assertSame('TEXT', $types['then']);
    }

    public function test_create_translates_boolean_to_integer(): void
    {
        $table = TableHelper::table('flags', [
            TableHelper::column('on', 'BOOLEAN'),
            TableHelper::column('off', 'BOOL'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);
        $types = $this->columnTypes('flags');
        $this->assertSame('INTEGER', $types['on']);
        $this->assertSame('INTEGER', $types['off']);
    }

    public function test_create_preserves_unknown_types_verbatim(): void
    {
        $table = TableHelper::table('weird', [
            TableHelper::column('x', 'NUMERIC'),
            TableHelper::column('y', 'GEOGRAPHY'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);
        $types = $this->columnTypes('weird');
        $this->assertSame('NUMERIC', $types['x']);
        $this->assertSame('GEOGRAPHY', $types['y']);
    }

    public function test_auto_increment_attribute_emits_autoincrement_keyword(): void
    {
        $table = TableHelper::table('seq', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTO_INCREMENT']),
            TableHelper::column('name', 'TEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);

        $this->db->query("INSERT INTO seq (name) VALUES ('a')");
        $this->db->query("INSERT INTO seq (name) VALUES ('b')");
        $rows = $this->db->query('SELECT id FROM seq ORDER BY id');
        $this->assertSame(1, (int) $rows[0]['id']);
        $this->assertSame(2, (int) $rows[1]['id']);
    }

    // ── indices ───────────────────────────────────────────────────────

    public function test_create_emits_separate_create_index_for_indices(): void
    {
        $index = new Index(['email'], 'idx_email', 'NORMAL');
        $table = TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('email', 'TEXT'),
        ], [$index]);

        (new TableCreateStrategy($this->db))->create($table);

        $indices = $this->db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='users'");
        $names = array_column($indices, 'name');
        $this->assertContains('idx_email', $names);
    }

    public function test_create_emits_unique_index_when_type_is_unique(): void
    {
        $index = new Index(['email'], 'uniq_email', 'UNIQUE');
        $table = TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('email', 'TEXT'),
        ], [$index]);

        (new TableCreateStrategy($this->db))->create($table);

        $this->db->query("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");
        try {
            $this->db->query("INSERT INTO users (id, email) VALUES (2, 'a@x.com')");
            $this->fail('expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
    }

    public function test_create_skips_primary_indices(): void
    {
        $index = new Index(['id'], 'PRIMARY', 'PRIMARY');
        $table = TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
        ], [$index]);

        (new TableCreateStrategy($this->db))->create($table);

        // The PRIMARY "index" is emitted inline on the column, so no
        // explicit index row should appear in sqlite_master for it.
        $rows = $this->db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='users'");
        $names = array_map(fn ($r) => strtoupper($r['name']), $rows);
        $this->assertNotContains('PRIMARY', $names);

        // Confirm the table still has the column as a real primary key.
        $info = $this->db->query('PRAGMA table_info("users")');
        $primary = array_filter($info, fn ($r) => (int) $r['pk'] === 1);
        $this->assertCount(1, $primary);
    }

    // ── update / sync ────────────────────────────────────────────────

    public function test_sync_adds_new_column(): void
    {
        $original = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('title', 'TEXT', ['NOT NULL']),
        ]);
        (new TableCreateStrategy($this->db))->create($original);
        $this->db->query("INSERT INTO docs (id, title) VALUES (1, 'hello')");

        $extended = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('title', 'TEXT', ['NOT NULL']),
            TableHelper::column('body', 'TEXT'),
        ]);
        (new TableUpdateStrategy($this->db))->syncColumns($extended);

        $rows = $this->db->query('SELECT id, title, body FROM docs');
        $this->assertSame('hello', $rows[0]['title']);
        $this->assertNull($rows[0]['body']);
    }

    public function test_sync_drops_removed_column(): void
    {
        $original = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('title', 'TEXT', ['NOT NULL']),
            TableHelper::column('legacy', 'TEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($original);
        $this->db->query("INSERT INTO docs (id, title, legacy) VALUES (1, 'hi', 'old')");

        $trimmed = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('title', 'TEXT', ['NOT NULL']),
        ]);
        (new TableUpdateStrategy($this->db))->syncColumns($trimmed);

        $names = $this->columnNames('docs');
        $this->assertNotContains('legacy', $names);
        $this->assertContains('title', $names);
    }

    public function test_sync_rebuilds_when_affinity_changes(): void
    {
        $original = TableHelper::table('items', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('value', 'TEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($original);
        $this->db->query("INSERT INTO items (id, value) VALUES (1, '42')");

        $changed = TableHelper::table('items', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('value', 'INTEGER'),
        ]);
        (new TableUpdateStrategy($this->db))->syncColumns($changed);

        $types = $this->columnTypes('items');
        $this->assertSame('INTEGER', $types['value']);

        $rows = $this->db->query('SELECT id, value FROM items');
        $this->assertSame(1, (int) $rows[0]['id']);
        $this->assertSame(42, (int) $rows[0]['value']);
    }

    public function test_sync_with_no_changes_is_noop(): void
    {
        $table = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('title', 'TEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($table);
        $this->db->query("INSERT INTO docs (id, title) VALUES (1, 'kept')");

        (new TableUpdateStrategy($this->db))->syncColumns($table);

        $rows = $this->db->query('SELECT * FROM docs');
        $this->assertCount(1, $rows);
        $this->assertSame('kept', $rows[0]['title']);
    }

    public function test_sync_rebuild_preserves_data_for_multiple_rows(): void
    {
        $original = TableHelper::table('items', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('value', 'TEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($original);
        for ($i = 1; $i <= 50; $i++) {
            $this->db->query("INSERT INTO items (id, value) VALUES ($i, '$i')");
        }

        $changed = TableHelper::table('items', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('value', 'INTEGER'),
        ]);
        (new TableUpdateStrategy($this->db))->syncColumns($changed);

        $count = (int) $this->db->query('SELECT COUNT(*) AS c FROM items')[0]['c'];
        $this->assertSame(50, $count);
    }

    public function test_sync_combined_add_and_drop(): void
    {
        $original = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('a', 'TEXT'),
            TableHelper::column('b', 'TEXT'),
        ]);
        (new TableCreateStrategy($this->db))->create($original);
        $this->db->query("INSERT INTO docs (id, a, b) VALUES (1, 'oldA', 'oldB')");

        $changed = TableHelper::table('docs', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY']),
            TableHelper::column('a', 'TEXT'),
            TableHelper::column('c', 'TEXT'),
        ]);
        (new TableUpdateStrategy($this->db))->syncColumns($changed);

        $names = $this->columnNames('docs');
        sort($names);
        $this->assertSame(['a', 'c', 'id'], $names);

        $rows = $this->db->query('SELECT id, a, c FROM docs');
        $this->assertSame('oldA', $rows[0]['a']);
        $this->assertNull($rows[0]['c']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function columnTypes(string $table): array
    {
        $info = $this->db->query("PRAGMA table_info(\"$table\")");
        $types = [];
        foreach ($info as $row) {
            $types[$row['name']] = $row['type'];
        }
        return $types;
    }

    /**
     * @return array<int, string>
     */
    private function columnNames(string $table): array
    {
        return array_column($this->db->query("PRAGMA table_info(\"$table\")"), 'name');
    }
}
