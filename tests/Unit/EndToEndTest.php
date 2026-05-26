<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\SQLite\Integration\Adapters\DatabaseDateAdapter;
use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Builders\SQLiteClauseBuilder;
use PHPNomad\SQLite\Integration\Strategies\QueryStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableUpdateStrategy;
use PHPNomad\SQLite\Integration\Tests\Support\IntegrationTestCase;
use PHPNomad\SQLite\Integration\Tests\Support\TableHelper;

/**
 * Whole-stack scenarios that exercise the integration the way a real
 * PHPNomad app would — create a table, insert several rows, query with
 * compound WHERE + ORDER + LIMIT, update some, delete some, and verify
 * counts, dates, and types behave end to end.
 */
class EndToEndTest extends IntegrationTestCase
{
    public function test_charter_like_lifecycle(): void
    {
        // Modeled after a Navigator-shaped Charter entity: id, title,
        // status, created_at, archived. Exercises create + indices,
        // unique constraint, datetime storage, multi-condition queries.
        $charters = TableHelper::table('charters', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('slug', 'VARCHAR', ['NOT NULL'], ['64']),
            TableHelper::column('title', 'TEXT', ['NOT NULL']),
            TableHelper::column('status', 'TEXT', ['NOT NULL']),
            TableHelper::column('created_at', 'DATETIME', ['NOT NULL']),
            TableHelper::column('archived', 'INTEGER'),
        ], [
            new Index(['status'], 'idx_status', 'NORMAL'),
            new Index(['slug'], 'uniq_slug', 'UNIQUE'),
        ], alias: 'c');

        (new TableCreateStrategy($this->db))->create($charters);

        $strategy = new QueryStrategy($this->db, $this->makeSchemaService('id'), new SQLiteClauseBuilder());
        $dateAdapter = new DatabaseDateAdapter();

        // Insert a batch of charters at known dates.
        $start = new \DateTime('2026-05-01 09:00:00');
        $rows = [
            ['slug' => 'product-launch',   'title' => 'Q2 Launch',        'status' => 'in-progress', 'archived' => 0],
            ['slug' => 'docs-overhaul',    'title' => 'Docs Overhaul',    'status' => 'queued',      'archived' => 0],
            ['slug' => 'pricing-revamp',   'title' => 'Pricing Revamp',   'status' => 'done',        'archived' => 0],
            ['slug' => 'sunset-legacy',    'title' => 'Sunset Old App',   'status' => 'done',        'archived' => 1],
            ['slug' => 'sprint-planning',  'title' => 'Sprint Planning',  'status' => 'in-progress', 'archived' => 0],
        ];

        $ids = [];
        foreach ($rows as $i => $row) {
            $row['created_at'] = $dateAdapter->toDatabaseDateString(
                (clone $start)->modify("+{$i} days")
            );
            $ids[] = $strategy->insert($charters, $row)['id'];
        }

        $this->assertSame([1, 2, 3, 4, 5], $ids);
        $this->assertSame(5, $strategy->estimatedCount($charters));

        // Unique constraint actually applies.
        try {
            $strategy->insert($charters, [
                'slug' => 'product-launch',
                'title' => 'Dup',
                'status' => 'queued',
                'created_at' => $dateAdapter->toDatabaseDateString(new \DateTime()),
                'archived' => 0,
            ]);
            $this->fail('expected unique constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }

        // Compound query: active charters, ordered by creation, limited.
        $where = (new SQLiteClauseBuilder())->useTable($charters);
        $where->where('archived', '=', 0);
        $where->andWhere('status', '!=', 'done');

        $qb = (new QueryBuilder())
            ->from($charters)
            ->select('*')
            ->where($where)
            ->orderBy('created_at', 'ASC')
            ->limit(10);

        $results = $strategy->query($qb);
        $slugs = array_column($results, 'slug');
        $this->assertSame(['product-launch', 'docs-overhaul', 'sprint-planning'], $slugs);

        // Update a status and confirm.
        $strategy->update($charters, ['id' => 1], ['status' => 'done']);
        $row = $this->db->query('SELECT status FROM charters WHERE id = 1');
        $this->assertSame('done', $row[0]['status']);

        // Delete an archived one.
        $strategy->delete($charters, ['id' => 4]);
        $this->assertSame(4, $strategy->estimatedCount($charters));

        // Date round-trips.
        $stored = $this->db->query('SELECT created_at FROM charters WHERE id = 1')[0]['created_at'];
        $restored = $dateAdapter->toDateTime($stored);
        $this->assertSame('2026-05-01 09:00:00', $restored->format('Y-m-d H:i:s'));

        // Migrate the schema: add a `due_date` column, rebuild without
        // affinity changes (simple ADD path).
        $extended = TableHelper::table('charters', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('slug', 'VARCHAR', ['NOT NULL'], ['64']),
            TableHelper::column('title', 'TEXT', ['NOT NULL']),
            TableHelper::column('status', 'TEXT', ['NOT NULL']),
            TableHelper::column('created_at', 'DATETIME', ['NOT NULL']),
            TableHelper::column('archived', 'INTEGER'),
            TableHelper::column('due_date', 'DATETIME'),
        ], alias: 'c');
        (new TableUpdateStrategy($this->db))->syncColumns($extended);

        // Data survived.
        $count = (int) $this->db->query('SELECT COUNT(*) AS c FROM charters')[0]['c'];
        $this->assertSame(4, $count);

        // due_date is queryable.
        $strategy->update($charters, ['id' => 5], [
            'status' => 'in-progress',
        ]);
        $this->db->query("UPDATE charters SET due_date = '2026-06-01 17:00:00' WHERE id = 5");
        $rows = $this->db->query("SELECT due_date FROM charters WHERE id = 5");
        $this->assertSame('2026-06-01 17:00:00', $rows[0]['due_date']);
    }

    public function test_data_survives_affinity_rebuild_in_realistic_app_scenario(): void
    {
        // Simulate "we initially stored prices as TEXT, oops, let's
        // migrate to INTEGER cents."
        $items = TableHelper::table('items', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('name', 'TEXT', ['NOT NULL']),
            TableHelper::column('price', 'TEXT', ['NOT NULL']),
        ], alias: 'i');
        (new TableCreateStrategy($this->db))->create($items);

        $strategy = new QueryStrategy($this->db, $this->makeSchemaService('id'), new SQLiteClauseBuilder());

        foreach ([
            ['name' => 'apple',  'price' => '100'],
            ['name' => 'banana', 'price' => '50'],
            ['name' => 'cherry', 'price' => '200'],
        ] as $row) {
            $strategy->insert($items, $row);
        }

        // Migration: same column, INTEGER affinity now.
        $migrated = TableHelper::table('items', [
            TableHelper::column('id', 'INTEGER', ['PRIMARY KEY', 'AUTOINCREMENT']),
            TableHelper::column('name', 'TEXT', ['NOT NULL']),
            TableHelper::column('price', 'INTEGER', ['NOT NULL']),
        ], alias: 'i');
        (new TableUpdateStrategy($this->db))->syncColumns($migrated);

        // Data survived and is now usable as numeric.
        $where = (new SQLiteClauseBuilder())->useTable($migrated);
        $where->where('price', '>', 75);
        $qb = (new QueryBuilder())->from($migrated)->select('*')->where($where);

        $results = $strategy->query($qb);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['apple', 'cherry'], $names);
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
}
