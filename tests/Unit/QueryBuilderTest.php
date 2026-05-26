<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Builders\SQLiteClauseBuilder;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\IntegrationTestCase;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;

class QueryBuilderTest extends IntegrationTestCase
{
    private function usersTable()
    {
        return TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('email', 'TEXT', ['NOT NULL']),
            TableHelper::column('display_name', 'TEXT'),
            TableHelper::column('status', 'TEXT'),
            TableHelper::column('credits', 'INTEGER'),
        ], alias: 'u');
    }

    private function ordersTable()
    {
        return TableHelper::table('orders', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('user_id', 'INTEGER'),
            TableHelper::column('total', 'INTEGER'),
        ], alias: 'o');
    }

    private function seedUsers(): void
    {
        (new TableCreateStrategy($this->db))->create($this->usersTable());
        $this->db->query(
            "INSERT INTO users (email, display_name, status, credits) VALUES
                ('a@example.com', 'Alpha',   'active',   100),
                ('b@example.com', 'Beta',    'inactive',  50),
                ('c@example.com', 'Gamma',   'active',    25),
                ('d@example.com', 'Delta',   'active',   200),
                ('e@example.com', 'Epsilon', 'inactive',   0)"
        );
    }

    // ── SELECT shape ──────────────────────────────────────────────────

    public function test_select_star_with_from_runs(): void
    {
        $this->seedUsers();
        $rows = $this->db->query(
            (new QueryBuilder())->select('*')->from($this->usersTable())->build()
        );
        $this->assertCount(5, $rows);
    }

    public function test_select_specific_columns_returns_just_those(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())->from($this->usersTable())->select('email')->build();
        $rows = $this->db->query($sql);
        $this->assertArrayHasKey('email', $rows[0]);
        $this->assertArrayNotHasKey('credits', $rows[0]);
    }

    public function test_select_without_from_throws(): void
    {
        $this->expectException(QueryBuilderException::class);
        (new QueryBuilder())->select('*')->build();
    }

    public function test_from_without_select_throws(): void
    {
        $this->expectException(QueryBuilderException::class);
        (new QueryBuilder())->from($this->usersTable())->build();
    }

    public function test_select_prepends_table_alias_to_field_names(): void
    {
        $sql = (new QueryBuilder())->from($this->usersTable())->select('email')->build();
        $this->assertStringContainsString('u.email', $sql);
    }

    // ── WHERE ─────────────────────────────────────────────────────────

    public function test_where_with_single_predicate_filters_rows(): void
    {
        $this->seedUsers();
        $table = $this->usersTable();
        $where = (new SQLiteClauseBuilder())->useTable($table);
        $where->where('status', '=', 'active');

        $sql = (new QueryBuilder())
            ->select('*')->from($table)->where($where)->build();
        $rows = $this->db->query($sql);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('active', $row['status']);
        }
    }

    public function test_where_with_compound_and_predicate(): void
    {
        $this->seedUsers();
        $table = $this->usersTable();
        $where = (new SQLiteClauseBuilder())->useTable($table);
        $where->where('status', '=', 'active');
        $where->andWhere('credits', '>', 50);

        $sql = (new QueryBuilder())
            ->from($table)->select('email')->where($where)->build();
        $rows = $this->db->query($sql);

        $emails = array_column($rows, 'email');
        sort($emails);
        $this->assertSame(['a@example.com', 'd@example.com'], $emails);
    }

    public function test_where_with_in_clause_filters_by_set(): void
    {
        $this->seedUsers();
        $table = $this->usersTable();
        $where = (new SQLiteClauseBuilder())->useTable($table);
        $where->where('email', 'IN', 'a@example.com', 'c@example.com');

        $sql = (new QueryBuilder())
            ->from($table)->select('email')->where($where)->build();
        $rows = $this->db->query($sql);

        $this->assertCount(2, $rows);
    }

    // ── ORDER BY / LIMIT / OFFSET ────────────────────────────────────

    public function test_order_by_ascending(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->from($this->usersTable())->select('email')
            ->orderBy('credits', 'ASC')
            ->build();
        $rows = $this->db->query($sql);
        $this->assertSame('e@example.com', $rows[0]['email']); // 0 credits
    }

    public function test_order_by_descending(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->from($this->usersTable())->select('email')
            ->orderBy('credits', 'DESC')
            ->build();
        $rows = $this->db->query($sql);
        $this->assertSame('d@example.com', $rows[0]['email']); // 200 credits
    }

    public function test_invalid_order_defaults_to_ascending(): void
    {
        $sql = (new QueryBuilder())
            ->select('*')->from($this->usersTable())
            ->orderBy('credits', 'sideways')
            ->build();
        $this->assertStringContainsString('ASC', $sql);
    }

    public function test_limit_caps_result_count(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->select('*')->from($this->usersTable())
            ->limit(2)
            ->build();
        $rows = $this->db->query($sql);
        $this->assertCount(2, $rows);
    }

    public function test_limit_with_offset_paginates(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->from($this->usersTable())->select('email')
            ->orderBy('id', 'ASC')
            ->limit(2)->offset(2)
            ->build();
        $rows = $this->db->query($sql);

        $this->assertCount(2, $rows);
        $this->assertSame('c@example.com', $rows[0]['email']);
        $this->assertSame('d@example.com', $rows[1]['email']);
    }

    // ── Aggregates ────────────────────────────────────────────────────

    public function test_count_star_returns_table_size(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->from($this->usersTable())
            ->count('*', 'total')
            ->build();
        $rows = $this->db->query($sql);
        $this->assertSame(5, (int) $rows[0]['total']);
    }

    public function test_sum_aggregates_a_column(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->from($this->usersTable())
            ->sum('credits', 'total')
            ->build();
        $rows = $this->db->query($sql);
        $this->assertSame(375, (int) $rows[0]['total']);
    }

    public function test_count_with_group_by_buckets_rows(): void
    {
        $this->seedUsers();
        $sql = (new QueryBuilder())
            ->from($this->usersTable())
            ->select('status')
            ->count('*', 'cnt')
            ->groupBy('status')
            ->orderBy('status', 'ASC')
            ->build();
        $rows = $this->db->query($sql);

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }
        $this->assertSame(['active' => 3, 'inactive' => 2], $byStatus);
    }

    // ── JOIN ──────────────────────────────────────────────────────────

    public function test_left_join_returns_combined_rows(): void
    {
        $this->seedUsers();
        $orders = $this->ordersTable();
        (new TableCreateStrategy($this->db))->create($orders);
        $this->db->query("INSERT INTO orders (user_id, total) VALUES (1, 99), (1, 50), (4, 200)");

        $users = $this->usersTable();
        $sql = (new QueryBuilder())
            ->from($users)
            ->select('email')
            ->leftJoin($orders, 'id', 'user_id')
            ->orderBy('id', 'ASC')
            ->build();

        $rows = $this->db->query($sql);
        // Alpha has 2 orders + Beta/Gamma/Epsilon have 0 + Delta has 1.
        // LEFT JOIN produces 2+1+1+1+1 = 6 rows.
        $this->assertCount(6, $rows);
    }

    // ── reset / re-use ────────────────────────────────────────────────

    public function test_build_resets_state(): void
    {
        $qb = (new QueryBuilder())->select('*')->from($this->usersTable());
        $qb->build();

        // After build, attempting to build again without re-specifying
        // select should throw.
        $this->expectException(QueryBuilderException::class);
        $qb->build();
    }

    public function test_explicit_reset_clears_select(): void
    {
        $qb = (new QueryBuilder())->from($this->usersTable())->select('email');
        $qb->reset();
        $this->expectException(QueryBuilderException::class);
        $qb->build();
    }

    public function test_reset_clauses_clears_named_state(): void
    {
        $sql = (new QueryBuilder())
            ->select('*')->from($this->usersTable())
            ->limit(5)
            ->resetClauses('limit')
            ->build();
        $this->assertStringNotContainsString('LIMIT', $sql);
    }

    // ── Composition ───────────────────────────────────────────────────

    public function test_full_stack_query_executes_correctly(): void
    {
        $this->seedUsers();
        $users = $this->usersTable();
        $where = (new SQLiteClauseBuilder())->useTable($users);
        $where->where('status', '=', 'active');
        $where->andWhere('credits', '>', 0);

        $sql = (new QueryBuilder())
            ->from($users)
            ->select('email')
            ->where($where)
            ->orderBy('credits', 'DESC')
            ->limit(2)
            ->build();

        $rows = $this->db->query($sql);
        $emails = array_column($rows, 'email');
        $this->assertSame(['d@example.com', 'a@example.com'], $emails);
    }
}
