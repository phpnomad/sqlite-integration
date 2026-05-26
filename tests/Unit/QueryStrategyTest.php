<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Builders\SQLiteClauseBuilder;
use PHPNomad\SQLite\Integration\Strategies\QueryStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\IntegrationTestCase;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;

class QueryStrategyTest extends IntegrationTestCase
{
    private QueryStrategy $strategy;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->table = TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('email', 'TEXT', ['NOT NULL']),
            TableHelper::column('display_name', 'TEXT'),
            TableHelper::column('status', 'TEXT'),
        ], alias: 'u');

        (new TableCreateStrategy($this->db))->create($this->table);
        $this->strategy = new QueryStrategy(
            $this->db,
            $this->makeSchemaService('id'),
            new SQLiteClauseBuilder(),
        );
    }

    public function test_insert_returns_autoincrement_identity(): void
    {
        $id1 = $this->strategy->insert($this->table, ['email' => 'a@example.com', 'display_name' => 'Alpha']);
        $id2 = $this->strategy->insert($this->table, ['email' => 'b@example.com', 'display_name' => 'Beta']);

        $this->assertSame(1, $id1['id']);
        $this->assertSame(2, $id2['id']);
    }

    public function test_insert_with_explicit_id_returns_that_id(): void
    {
        $id = $this->strategy->insert($this->table, ['id' => 99, 'email' => 'c@example.com']);
        $this->assertSame(99, $id['id']);

        $rows = $this->db->query('SELECT id, email FROM users WHERE id = 99');
        $this->assertSame('c@example.com', $rows[0]['email']);
    }

    public function test_insert_persists_unicode_safely(): void
    {
        $this->strategy->insert($this->table, [
            'email' => 'user@example.com',
            'display_name' => '日本語の表示',
        ]);
        $rows = $this->db->query('SELECT display_name FROM users');
        $this->assertSame('日本語の表示', $rows[0]['display_name']);
    }

    public function test_insert_persists_hostile_strings_safely(): void
    {
        $this->strategy->insert($this->table, [
            'email' => 'attacker@example.com',
            'display_name' => "'; DROP TABLE users; --",
        ]);

        // If the injection landed, the next SELECT would throw.
        $rows = $this->db->query('SELECT display_name FROM users');
        $this->assertSame("'; DROP TABLE users; --", $rows[0]['display_name']);
    }

    public function test_insert_then_query_via_builder(): void
    {
        $this->strategy->insert($this->table, ['email' => 'a@example.com', 'display_name' => 'Alpha']);

        $clauseBuilder = (new SQLiteClauseBuilder())->useTable($this->table);
        $clauseBuilder->where('email', '=', 'a@example.com');

        $qb = (new QueryBuilder())
            ->from($this->table)
            ->select('*')
            ->where($clauseBuilder);

        $rows = $this->strategy->query($qb);
        $this->assertCount(1, $rows);
        $this->assertSame('Alpha', $rows[0]['display_name']);
    }

    public function test_query_raises_record_not_found_for_empty_result(): void
    {
        $clauseBuilder = (new SQLiteClauseBuilder())->useTable($this->table);
        $clauseBuilder->where('email', '=', 'missing@example.com');
        $qb = (new QueryBuilder())->from($this->table)->select('*')->where($clauseBuilder);

        $this->expectException(RecordNotFoundException::class);
        $this->strategy->query($qb);
    }

    public function test_update_changes_values(): void
    {
        $id = $this->strategy->insert($this->table, ['email' => 'before@x.com', 'display_name' => 'Old']);
        $this->strategy->update($this->table, ['id' => $id['id']], ['display_name' => 'New']);

        $row = $this->db->query('SELECT display_name FROM users WHERE id = ' . $id['id']);
        $this->assertSame('New', $row[0]['display_name']);
    }

    public function test_update_with_multiple_columns(): void
    {
        $id = $this->strategy->insert($this->table, ['email' => 'a@x.com', 'display_name' => 'A', 'status' => 'pending']);
        $this->strategy->update(
            $this->table,
            ['id' => $id['id']],
            ['display_name' => 'A-Updated', 'status' => 'active']
        );

        $row = $this->db->query('SELECT display_name, status FROM users WHERE id = ' . $id['id']);
        $this->assertSame('A-Updated', $row[0]['display_name']);
        $this->assertSame('active', $row[0]['status']);
    }

    public function test_update_with_no_matching_row_is_silent(): void
    {
        // MySQL semantics: matched-but-no-change isn't an error, and
        // missing-row is also not (the affected-rows count is 0 but the
        // statement succeeds). Our strategy mirrors that.
        $this->strategy->update($this->table, ['id' => 999], ['display_name' => 'X']);

        $rows = $this->db->query('SELECT * FROM users');
        $this->assertEmpty($rows);
    }

    public function test_delete_removes_row(): void
    {
        $id = $this->strategy->insert($this->table, ['email' => 'gone@x.com']);
        $this->strategy->delete($this->table, ['id' => $id['id']]);

        $rows = $this->db->query('SELECT id FROM users');
        $this->assertEmpty($rows);
    }

    public function test_delete_with_no_matching_row_is_silent(): void
    {
        $this->strategy->delete($this->table, ['id' => 12345]);
        $this->assertSame(0, $this->strategy->estimatedCount($this->table));
    }

    public function test_delete_only_affects_matching_rows(): void
    {
        $this->strategy->insert($this->table, ['email' => 'a@x.com']);
        $kept = $this->strategy->insert($this->table, ['email' => 'b@x.com']);
        $this->strategy->insert($this->table, ['email' => 'c@x.com']);

        $this->strategy->delete($this->table, ['id' => $kept['id']]);

        $rows = $this->db->query('SELECT email FROM users ORDER BY id');
        $emails = array_column($rows, 'email');
        $this->assertSame(['a@x.com', 'c@x.com'], $emails);
    }

    public function test_estimated_count_returns_table_size(): void
    {
        $this->assertSame(0, $this->strategy->estimatedCount($this->table));
        $this->strategy->insert($this->table, ['email' => '1@x.com']);
        $this->strategy->insert($this->table, ['email' => '2@x.com']);
        $this->assertSame(2, $this->strategy->estimatedCount($this->table));
    }

    public function test_insert_missing_required_field_raises_datastore_error(): void
    {
        // `email` is NOT NULL; inserting without it should fail at the SQL
        // layer and bubble as a DatastoreErrorException.
        $this->expectException(DatastoreErrorException::class);
        $this->strategy->insert($this->table, ['display_name' => 'Orphan']);
    }

    public function test_composite_primary_key_returns_passed_identity(): void
    {
        $composite = TableHelper::table('memberships', [
            TableHelper::column('user_id', 'INTEGER', ['NOT NULL']),
            TableHelper::column('group_id', 'INTEGER', ['NOT NULL']),
            TableHelper::column('role', 'TEXT'),
        ], alias: 'm');

        $this->db->query('CREATE TABLE memberships (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL, role TEXT, PRIMARY KEY (user_id, group_id))');

        $strategy = new QueryStrategy(
            $this->db,
            $this->makeCompositeSchemaService(),
            new SQLiteClauseBuilder(),
        );

        $id = $strategy->insert($composite, ['user_id' => 5, 'group_id' => 12, 'role' => 'admin']);
        $this->assertSame(['user_id' => 5, 'group_id' => 12], $id);
    }

    public function test_insert_with_missing_non_auto_identity_throws(): void
    {
        // A non-autoincrement primary key column where the data omits a
        // value can't have its identity resolved.
        $manual = TableHelper::table('events', [
            TableHelper::column('id', 'TEXT', ['PRIMARY KEY']),
            TableHelper::column('name', 'TEXT'),
        ], alias: 'e');

        $this->db->query('CREATE TABLE events (id TEXT PRIMARY KEY, name TEXT)');

        $strategy = new QueryStrategy(
            $this->db,
            $this->makeManualIdSchemaService(),
            new SQLiteClauseBuilder(),
        );

        $this->expectException(DatastoreErrorException::class);
        $strategy->insert($manual, ['name' => 'orphan']);
    }

    private function makeSchemaService(string $idName): TableSchemaService
    {
        $column = new Column($idName, 'INTEGER', null, 'PRIMARY KEY', 'AUTOINCREMENT');

        return new class($column) extends TableSchemaService {
            public function __construct(private Column $primary) {}
            public function getPrimaryColumnsForTable(Table $table): array
            {
                return [$this->primary];
            }
        };
    }

    private function makeCompositeSchemaService(): TableSchemaService
    {
        $userId = new Column('user_id', 'INTEGER', null, 'NOT NULL');
        $groupId = new Column('group_id', 'INTEGER', null, 'NOT NULL');

        return new class($userId, $groupId) extends TableSchemaService {
            public function __construct(private Column $a, private Column $b) {}
            public function getPrimaryColumnsForTable(Table $table): array
            {
                return [$this->a, $this->b];
            }
        };
    }

    private function makeManualIdSchemaService(): TableSchemaService
    {
        // Primary key with no AUTOINCREMENT attribute → identity must
        // come from the supplied data.
        $column = new Column('id', 'TEXT', null, 'PRIMARY KEY');

        return new class($column) extends TableSchemaService {
            public function __construct(private Column $primary) {}
            public function getPrimaryColumnsForTable(Table $table): array
            {
                return [$this->primary];
            }
        };
    }
}
