<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use DateTime;
use PHPNomad\SQLite\Integration\Adapters\DatabaseDateAdapter;
use PHPNomad\SQLite\Integration\Tests\TestCase;

class DatabaseDateAdapterTest extends TestCase
{
    public function test_to_database_date_string_uses_canonical_format(): void
    {
        $adapter = new DatabaseDateAdapter();
        $dt = new DateTime('2026-05-26 14:33:18');
        $this->assertSame('2026-05-26 14:33:18', $adapter->toDatabaseDateString($dt));
    }

    public function test_round_trip_preserves_seconds(): void
    {
        $adapter = new DatabaseDateAdapter();
        $original = new DateTime('2026-05-26 14:33:18');
        $stored = $adapter->toDatabaseDateString($original);
        $restored = $adapter->toDateTime($stored);

        $this->assertSame($original->format('U'), $restored->format('U'));
    }

    public function test_iso_8601_with_t_separator_is_tolerated(): void
    {
        // SQLite's CURRENT_TIMESTAMP-like values use a T separator. Our
        // adapter falls back to general DateTime parsing if the strict
        // format doesn't match.
        $restored = (new DatabaseDateAdapter())->toDateTime('2026-05-26T14:33:18');
        $this->assertSame('2026-05-26 14:33:18', $restored->format('Y-m-d H:i:s'));
    }

    public function test_date_only_value_is_tolerated(): void
    {
        $restored = (new DatabaseDateAdapter())->toDateTime('2026-05-26');
        $this->assertSame('2026-05-26', $restored->format('Y-m-d'));
    }

    public function test_format_matches_mysql_adapter_shape(): void
    {
        // The whole point of this adapter is that entity classes don't
        // need to know whether they're on MySQL or SQLite. The string
        // format must be identical between the two.
        $adapter = new DatabaseDateAdapter();
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', '2020-01-01 12:34:56');
        $this->assertSame('2020-01-01 12:34:56', $adapter->toDatabaseDateString($dt));
    }

    public function test_invalid_input_throws(): void
    {
        $this->expectException(\Exception::class);
        (new DatabaseDateAdapter())->toDateTime('not a date');
    }
}
