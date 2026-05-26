<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Builders\SQLiteClauseBuilder;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\IntegrationTestCase;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;

class SQLiteClauseBuilderTest extends IntegrationTestCase
{
    private function productsTable()
    {
        return TableHelper::table('products', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('name', 'TEXT'),
            TableHelper::column('price', 'INTEGER'),
            TableHelper::column('stock', 'INTEGER'),
            TableHelper::column('category', 'TEXT'),
            TableHelper::column('archived', 'INTEGER'),
        ], alias: 'p');
    }

    private function seedProducts(): void
    {
        (new TableCreateStrategy($this->db))->create($this->productsTable());
        $this->db->query(
            "INSERT INTO products (name, price, stock, category, archived) VALUES
                ('Apple',  100, 10, 'fruit',   0),
                ('Banana',  50, 30, 'fruit',   0),
                ('Carrot',  40, 25, 'veggie',  0),
                ('Donut',  200,  5, 'sweet',   0),
                ('Eggs',   300,  0, 'protein', 0),
                ('Fudge',  150, 12, 'sweet',   1),
                ('Grape',   80, 20, 'fruit',   1)"
        );
    }

    private function exec(SQLiteClauseBuilder $where): array
    {
        $sql = (new QueryBuilder())
            ->from($this->productsTable())
            ->select('*')
            ->where($where)
            ->build();
        return $this->db->query($sql);
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    // ── operators ─────────────────────────────────────────────────────

    public function test_equals_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', '=', 'Apple');
        $this->assertSame(['Apple'], $this->names($this->exec($where)));
    }

    public function test_not_equals_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('category', '!=', 'fruit');
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Carrot', 'Donut', 'Eggs', 'Fudge'], $names);
    }

    public function test_less_than_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('price', '<', 100);
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Banana', 'Carrot', 'Grape'], $names);
    }

    public function test_greater_than_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('price', '>', 150);
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Donut', 'Eggs'], $names);
    }

    public function test_less_than_or_equal_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('price', '<=', 100);
        $this->assertCount(4, $this->exec($where));
    }

    public function test_greater_than_or_equal_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('price', '>=', 200);
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Donut', 'Eggs'], $names);
    }

    public function test_like_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', 'LIKE', 'A%');
        $this->assertSame(['Apple'], $this->names($this->exec($where)));
    }

    public function test_not_like_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', 'NOT LIKE', '%n%');
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Apple', 'Carrot', 'Eggs', 'Fudge', 'Grape'], $names);
    }

    public function test_in_operator_with_multiple_values(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('category', 'IN', 'fruit', 'sweet');
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Apple', 'Banana', 'Donut', 'Fudge', 'Grape'], $names);
    }

    public function test_in_operator_with_single_value(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('category', 'IN', 'veggie');
        $this->assertSame(['Carrot'], $this->names($this->exec($where)));
    }

    public function test_not_in_operator(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('category', 'NOT IN', 'fruit', 'sweet');
        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Carrot', 'Eggs'], $names);
    }

    public function test_invalid_operator_is_silently_dropped(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', 'INVALID', 'anything');

        // An invalid operator emits no clause; we get every row back.
        $this->assertCount(7, $this->exec($where));
    }

    // ── chaining ──────────────────────────────────────────────────────

    public function test_and_where_narrows_results(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('category', '=', 'fruit');
        $where->andWhere('price', '<', 100);

        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Banana', 'Grape'], $names);
    }

    public function test_or_where_widens_results(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', '=', 'Apple');
        $where->orWhere('name', '=', 'Donut');

        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Apple', 'Donut'], $names);
    }

    public function test_chained_and_or_mix(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('category', '=', 'fruit');
        $where->andWhere('stock', '>', 15);
        $where->orWhere('category', '=', 'sweet');

        $names = $this->names($this->exec($where));
        sort($names);
        // fruits with stock > 15: Banana(30), Grape(20)
        // plus all sweets: Donut, Fudge
        $this->assertSame(['Banana', 'Donut', 'Fudge', 'Grape'], $names);
    }

    // ── groups ────────────────────────────────────────────────────────

    public function test_and_group_combines_inner_clauses(): void
    {
        $this->seedProducts();

        $inner1 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner1->where('category', '=', 'fruit');

        $inner2 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner2->where('price', '>=', 80);

        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->group('AND', $inner1, $inner2);

        $names = $this->names($this->exec($where));
        sort($names);
        // fruits AND price >= 80: Apple(100), Grape(80)
        $this->assertSame(['Apple', 'Grape'], $names);
    }

    public function test_or_group_combines_inner_clauses(): void
    {
        $this->seedProducts();

        $inner1 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner1->where('name', '=', 'Eggs');

        $inner2 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner2->where('name', '=', 'Donut');

        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->group('OR', $inner1, $inner2);

        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Donut', 'Eggs'], $names);
    }

    public function test_and_group_combines_with_existing_clauses(): void
    {
        $this->seedProducts();

        $inner1 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner1->where('category', '=', 'sweet');

        $inner2 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner2->where('archived', '=', 0);

        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('price', '>', 50);
        $where->andGroup('AND', $inner1, $inner2);

        // price > 50 AND (category = 'sweet' AND archived = 0): just Donut.
        $this->assertSame(['Donut'], $this->names($this->exec($where)));
    }

    public function test_or_group_combines_with_existing_clauses(): void
    {
        $this->seedProducts();

        $inner1 = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $inner1->where('category', '=', 'protein');

        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', '=', 'Apple');
        $where->orGroup('AND', $inner1);

        $names = $this->names($this->exec($where));
        sort($names);
        $this->assertSame(['Apple', 'Eggs'], $names);
    }

    // ── reset / field filtering ───────────────────────────────────────

    public function test_reset_clears_state(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', '=', 'Apple');
        $where->reset();
        $where->where('name', '=', 'Banana');

        $this->assertSame(['Banana'], $this->names($this->exec($where)));
    }

    public function test_unknown_field_is_filtered_out_silently(): void
    {
        // tableHasField returns false for columns the table doesn't declare,
        // so the clause builder skips the predicate. The result is every row
        // because no WHERE was emitted.
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('not_a_column', '=', 'anything');
        $this->assertCount(7, $this->exec($where));
    }

    public function test_build_returns_empty_string_when_no_clauses(): void
    {
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $this->assertSame('', $where->build());
    }

    // ── value escaping under the hood ────────────────────────────────

    public function test_string_values_in_where_clauses_are_quoted_safely(): void
    {
        $this->seedProducts();
        $this->db->query("INSERT INTO products (name, price, stock, category, archived) VALUES ('o''Malley', 99, 1, 'misc', 0)");

        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', '=', "o'Malley");

        $names = $this->names($this->exec($where));
        $this->assertSame(["o'Malley"], $names);
    }

    public function test_hostile_value_does_not_terminate_the_clause(): void
    {
        $this->seedProducts();
        $where = (new SQLiteClauseBuilder())->useTable($this->productsTable());
        $where->where('name', '=', "'; DROP TABLE products; --");

        // No rows match the hostile literal; the table is intact.
        $this->assertCount(0, $this->exec($where));

        $survivors = $this->db->query('SELECT COUNT(*) AS c FROM products');
        $this->assertSame(7, (int) $survivors[0]['c']);
    }
}
