<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\IntegrationTestCase;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;

/**
 * Coverage tests for QueryBuilder paths that the main suite skipped —
 * rightJoin, multi-column groupBy, default-alias aggregates, count of a
 * specific field, chained joins.
 */
class QueryBuilderCoverageTest extends IntegrationTestCase
{
    private function ordersTable()
    {
        return TableHelper::table('orders', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('user_id', 'INTEGER'),
            TableHelper::column('product_id', 'INTEGER'),
            TableHelper::column('total', 'INTEGER'),
            TableHelper::column('region', 'TEXT'),
            TableHelper::column('status', 'TEXT'),
        ], alias: 'o');
    }

    private function usersTable()
    {
        return TableHelper::table('users', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('name', 'TEXT'),
        ], alias: 'u');
    }

    private function productsTable()
    {
        return TableHelper::table('products', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('label', 'TEXT'),
        ], alias: 'p');
    }

    private function seed(): void
    {
        (new TableCreateStrategy($this->db))->create($this->usersTable());
        (new TableCreateStrategy($this->db))->create($this->productsTable());
        (new TableCreateStrategy($this->db))->create($this->ordersTable());

        $this->db->query("INSERT INTO users (name) VALUES ('Alice'), ('Bob'), ('Carol')");
        $this->db->query("INSERT INTO products (label) VALUES ('Widget'), ('Gizmo')");
        $this->db->query(
            "INSERT INTO orders (user_id, product_id, total, region, status) VALUES
                (1, 1, 100, 'us', 'paid'),
                (1, 2,  50, 'us', 'paid'),
                (2, 1, 200, 'us', 'refunded'),
                (2, 2, 150, 'eu', 'paid'),
                (3, 1,  75, 'eu', 'paid')"
        );
    }

    // ── rightJoin ─────────────────────────────────────────────────────

    public function test_right_join_emits_right_join_clause(): void
    {
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->select('*')
            ->rightJoin($this->usersTable(), 'user_id', 'id')
            ->build();

        $this->assertStringContainsString('RIGHT JOIN', $sql);
        $this->assertStringContainsString('o.user_id', $sql);
        $this->assertStringContainsString('u.id', $sql);
    }

    public function test_right_join_returns_correct_rows(): void
    {
        $this->seed();

        // RIGHT JOIN users from orders → every user appears, even those
        // without orders (which we don't have, but the syntax must work).
        // Add a user with no orders to verify.
        $this->db->query("INSERT INTO users (name) VALUES ('Daniel')");

        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->select('*')
            ->rightJoin($this->usersTable(), 'user_id', 'id')
            ->build();

        $rows = $this->db->query($sql);
        $names = array_unique(array_column($rows, 'name'));
        sort($names);
        $this->assertContains('Daniel', $names);
    }

    // ── multi-column groupBy ─────────────────────────────────────────

    public function test_group_by_multiple_columns(): void
    {
        $this->seed();

        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->select('region', 'status')
            ->count('*', 'cnt')
            ->groupBy('region', 'status')
            ->orderBy('region', 'ASC')
            ->build();

        $rows = $this->db->query($sql);

        $buckets = [];
        foreach ($rows as $row) {
            $buckets[$row['region'] . '/' . $row['status']] = (int) $row['cnt'];
        }

        $this->assertSame(
            ['eu/paid' => 2, 'us/paid' => 2, 'us/refunded' => 1],
            $buckets
        );
    }

    // ── default-alias aggregates ─────────────────────────────────────

    public function test_count_without_explicit_alias_uses_field_count_suffix(): void
    {
        $this->seed();
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->count('id')
            ->build();

        // Default alias is "<field>_count".
        $this->assertStringContainsString('id_count', $sql);

        $rows = $this->db->query($sql);
        $this->assertSame(5, (int) $rows[0]['id_count']);
    }

    public function test_count_of_star_with_default_alias_uses_valid_identifier(): void
    {
        $this->seed();
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->count('*')
            ->build();

        // Default alias for COUNT(*) is "count" — not "*_count", which
        // would be invalid SQL. (The mysql-integration ships with the
        // invalid form; the SQLite port fixes it.)
        $this->assertStringContainsString(' as count', $sql);

        $rows = $this->db->query($sql);
        $this->assertSame(5, (int) $rows[0]['count']);
    }

    public function test_count_of_specific_field_prepends_alias(): void
    {
        $this->seed();
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->count('total', 'tcount')
            ->build();

        // Non-star fields get table-alias prefixed.
        $this->assertStringContainsString('COUNT(o.total)', $sql);

        $rows = $this->db->query($sql);
        $this->assertSame(5, (int) $rows[0]['tcount']);
    }

    public function test_sum_without_explicit_alias_uses_field_sum_suffix(): void
    {
        $this->seed();
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->sum('total')
            ->build();

        $this->assertStringContainsString('total_sum', $sql);

        $rows = $this->db->query($sql);
        $this->assertSame(575, (int) $rows[0]['total_sum']);
    }

    // ── chained joins ────────────────────────────────────────────────

    public function test_multiple_joins_compose(): void
    {
        $this->seed();

        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->select('*')
            ->leftJoin($this->usersTable(), 'user_id', 'id')
            ->leftJoin($this->productsTable(), 'product_id', 'id')
            ->build();

        // Both JOINs should appear; columns from all three tables addressable.
        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('products', $sql);

        $rows = $this->db->query($sql);
        $this->assertCount(5, $rows);
        // Each row should have columns from all three tables joined in.
        $this->assertArrayHasKey('label', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
    }

    public function test_left_then_right_join(): void
    {
        $this->seed();

        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->select('*')
            ->leftJoin($this->productsTable(), 'product_id', 'id')
            ->rightJoin($this->usersTable(), 'user_id', 'id')
            ->build();

        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertStringContainsString('RIGHT JOIN', $sql);
    }

    // ── reset / re-use behavior ──────────────────────────────────────

    public function test_join_state_clears_between_builds(): void
    {
        $qb = (new QueryBuilder());
        $qb->from($this->ordersTable())
           ->select('*')
           ->leftJoin($this->usersTable(), 'user_id', 'id')
           ->build();

        // Build the same builder again with a fresh from(); the prior
        // join must not bleed through.
        $sql = $qb->from($this->ordersTable())
            ->select('*')
            ->build();

        $this->assertStringNotContainsString('JOIN', $sql);
    }

    public function test_order_by_state_clears_between_builds(): void
    {
        $qb = (new QueryBuilder());
        $qb->from($this->ordersTable())->select('*')->orderBy('total', 'DESC')->build();

        $sql = $qb->from($this->ordersTable())->select('*')->build();
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    public function test_group_by_state_clears_between_builds(): void
    {
        $qb = (new QueryBuilder());
        $qb->from($this->ordersTable())->select('*')->groupBy('region')->build();

        $sql = $qb->from($this->ordersTable())->select('*')->build();
        $this->assertStringNotContainsString('GROUP BY', $sql);
    }

    // ── multi-field select ───────────────────────────────────────────

    public function test_select_multiple_fields(): void
    {
        $this->seed();
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->select('region', 'status', 'total')
            ->build();

        $this->assertStringContainsString('o.region', $sql);
        $this->assertStringContainsString('o.status', $sql);
        $this->assertStringContainsString('o.total', $sql);
    }

    // ── aggregate composition ────────────────────────────────────────

    public function test_count_and_sum_together(): void
    {
        $this->seed();
        $sql = (new QueryBuilder())
            ->from($this->ordersTable())
            ->count('*', 'rows')
            ->sum('total', 'gross')
            ->build();

        $rows = $this->db->query($sql);
        $this->assertSame(5, (int) $rows[0]['rows']);
        $this->assertSame(575, (int) $rows[0]['gross']);
    }
}
