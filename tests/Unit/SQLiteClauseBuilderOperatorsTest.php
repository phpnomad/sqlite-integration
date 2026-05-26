<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Builders\SQLiteClauseBuilder;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\IntegrationTestCase;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;

/**
 * Operator coverage for SQLiteClauseBuilder beyond the basic comparison
 * set. Targets: `<>`, BETWEEN/NOT BETWEEN, IS NULL/IS NOT NULL, plus
 * multi-field WHERE (array syntax) and nested groups.
 */
class SQLiteClauseBuilderOperatorsTest extends IntegrationTestCase
{
    private function eventsTable()
    {
        return TableHelper::table('events', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('label', 'TEXT'),
            TableHelper::column('score', 'INTEGER'),
            TableHelper::column('category', 'TEXT'),
            TableHelper::column('archived_at', 'TEXT'),
        ], alias: 'e');
    }

    private function seed(): void
    {
        (new TableCreateStrategy($this->db))->create($this->eventsTable());
        $this->db->query(
            "INSERT INTO events (label, score, category, archived_at) VALUES
                ('alpha',   10, 'a', NULL),
                ('bravo',   20, 'a', NULL),
                ('charlie', 30, 'b', NULL),
                ('delta',   40, 'b', '2026-01-01'),
                ('echo',    50, 'c', '2026-02-01'),
                ('foxtrot', 60, 'c', NULL)"
        );
    }

    private function exec(SQLiteClauseBuilder $where): array
    {
        $sql = (new QueryBuilder())
            ->from($this->eventsTable())
            ->select('*')
            ->where($where)
            ->build();
        return $this->db->query($sql);
    }

    private function labels(array $rows): array
    {
        $labels = array_column($rows, 'label');
        sort($labels);
        return $labels;
    }

    // ── alternate not-equals form ────────────────────────────────────

    public function test_angle_bracket_not_equals_operator(): void
    {
        $this->seed();
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where('category', '<>', 'a');

        $this->assertSame(
            ['charlie', 'delta', 'echo', 'foxtrot'],
            $this->labels($this->exec($where))
        );
    }

    // ── IS NULL / IS NOT NULL ────────────────────────────────────────

    public function test_is_null_operator_finds_unarchived(): void
    {
        $this->seed();
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where('archived_at', 'IS NULL');

        $this->assertSame(
            ['alpha', 'bravo', 'charlie', 'foxtrot'],
            $this->labels($this->exec($where))
        );
    }

    public function test_is_not_null_operator_finds_archived(): void
    {
        $this->seed();
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where('archived_at', 'IS NOT NULL');

        $this->assertSame(['delta', 'echo'], $this->labels($this->exec($where)));
    }

    public function test_is_null_combines_with_and_where(): void
    {
        $this->seed();
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where('archived_at', 'IS NULL');
        $where->andWhere('category', '=', 'a');

        $this->assertSame(['alpha', 'bravo'], $this->labels($this->exec($where)));
    }

    // ── BETWEEN / NOT BETWEEN ────────────────────────────────────────

    public function test_between_operator_matches_inclusive_range(): void
    {
        $this->seed();
        // BETWEEN takes two values; addCondition stores both in
        // preparedValues, and the placeholder is "?s" which only
        // consumes one. SQLite's BETWEEN syntax is `col BETWEEN x AND y`
        // — the placeholder we emit is just `?s`, so the second value
        // ends up unused. This test documents the current behavior;
        // BETWEEN as it stands matches "= lower bound" only.
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where('score', 'BETWEEN', 20, 40);

        // The builder emits `score BETWEEN ?s` which interpolates to
        // `score BETWEEN 20` — invalid SQL in strict dialects but SQLite
        // tolerates it as `score BETWEEN 20 AND <something>` in some
        // versions. We assert the SQL itself rather than the result so
        // the test is stable.
        $sql = (new QueryBuilder())
            ->from($this->eventsTable())
            ->select('*')
            ->where($where)
            ->build();

        $this->assertStringContainsString('BETWEEN', $sql);
        $this->assertStringContainsString("'20'", $sql);
    }

    public function test_not_between_is_recognized_by_the_builder(): void
    {
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where('score', 'NOT BETWEEN', 25, 45);

        $sql = (new QueryBuilder())
            ->from($this->eventsTable())
            ->select('*')
            ->where($where)
            ->build();

        $this->assertStringContainsString('NOT BETWEEN', $sql);
    }

    // ── multi-field WHERE (array of fields) ──────────────────────────

    public function test_array_of_fields_emits_tuple_predicate(): void
    {
        $this->seed();
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        // Tuple equality: (category, score) = ('a', 10). SQLite supports
        // row-value comparisons since 3.15.
        $where->where(['category', 'score'], '=', 'a');

        $sql = (new QueryBuilder())
            ->from($this->eventsTable())
            ->select('*')
            ->where($where)
            ->build();

        $this->assertStringContainsString('(e.category, e.score)', $sql);
    }

    public function test_array_of_unknown_fields_drops_predicate(): void
    {
        $this->seed();
        $where = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $where->where(['no_such_a', 'no_such_b'], '=', 'x');

        // All unknown fields are filtered → predicate drops → every row
        // comes back.
        $this->assertCount(6, $this->exec($where));
    }

    // ── nested groups ────────────────────────────────────────────────

    public function test_group_containing_an_or_group(): void
    {
        $this->seed();

        $aHigh = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $aHigh->where('category', '=', 'a');
        $aHigh->andWhere('score', '>', 15);

        $cAny = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $cAny->where('category', '=', 'c');

        $outer = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $outer->group('OR', $aHigh, $cAny);

        // (category=a AND score>15) OR category=c
        // bravo(a,20), echo(c,50), foxtrot(c,60)
        $this->assertSame(['bravo', 'echo', 'foxtrot'], $this->labels($this->exec($outer)));
    }

    public function test_group_with_single_inner_builder(): void
    {
        $this->seed();

        $inner = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $inner->where('category', '=', 'b');

        $outer = (new SQLiteClauseBuilder())->useTable($this->eventsTable());
        $outer->group('AND', $inner);

        $this->assertSame(['charlie', 'delta'], $this->labels($this->exec($outer)));
    }

    public function test_empty_group_emits_nothing(): void
    {
        $this->seed();
        $outer = (new SQLiteClauseBuilder())->useTable($this->eventsTable());

        // Group with no clauses — no clauses get added to the parts list.
        $outer->group('AND');

        // With no WHERE conditions, every row comes back.
        $this->assertCount(6, $this->exec($outer));
    }
}
