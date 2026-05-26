<?php

namespace PHPNomad\SQLite\Integration\Tests\Unit;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\SQLite\Integration\Connection;
use PHPNomad\SQLite\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\SQLite\Integration\Tests\TestCase;

class PdoDatabaseStrategyTest extends TestCase
{
    private function build(): PdoDatabaseStrategy
    {
        return new PdoDatabaseStrategy(new Connection('sqlite::memory:'));
    }

    // ── ?n: identifiers ────────────────────────────────────────────────

    public function test_identifier_placeholder_is_double_quoted(): void
    {
        $this->assertSame('"users"', $this->build()->parse('?n', 'users'));
    }

    public function test_identifier_with_embedded_double_quote_is_escaped(): void
    {
        $this->assertSame('"weird""name"', $this->build()->parse('?n', 'weird"name'));
    }

    public function test_identifier_with_single_quote_is_left_alone(): void
    {
        $this->assertSame('"o\'malley"', $this->build()->parse('?n', "o'malley"));
    }

    public function test_identifier_with_unicode_passes_through(): void
    {
        $this->assertSame('"日本"', $this->build()->parse('?n', '日本'));
    }

    // ── ?s: strings ────────────────────────────────────────────────────

    public function test_string_placeholder_uses_pdo_quote(): void
    {
        $this->assertSame("'don''t'", $this->build()->parse('?s', "don't"));
    }

    public function test_string_with_double_quotes_is_passed_through(): void
    {
        $this->assertSame("'say \"hi\"'", $this->build()->parse('?s', 'say "hi"'));
    }

    public function test_empty_string_renders_as_empty_quoted_literal(): void
    {
        $this->assertSame("''", $this->build()->parse('?s', ''));
    }

    public function test_string_with_null_byte_is_quoted_safely(): void
    {
        $result = $this->build()->parse('?s', "abc\0def");
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
    }

    public function test_string_with_unicode_passes_through(): void
    {
        $this->assertSame("'こんにちは'", $this->build()->parse('?s', 'こんにちは'));
    }

    public function test_string_with_classic_injection_attempt_is_neutralized(): void
    {
        $hostile = "'; DROP TABLE users; --";
        $quoted = $this->build()->parse('?s', $hostile);

        // The hostile single quote must be doubled so it terminates inside
        // the literal rather than closing it.
        $this->assertStringContainsString("''", $quoted);
        $this->assertStringNotContainsString("; DROP TABLE users", substr($quoted, 0, 5));
    }

    // ── ?i and ?d: numeric ────────────────────────────────────────────

    public function test_integer_placeholder_renders_as_bare_number(): void
    {
        $this->assertSame('42', $this->build()->parse('?i', '42'));
    }

    public function test_integer_placeholder_coerces_string_to_int(): void
    {
        $this->assertSame('12', $this->build()->parse('?i', '12abc'));
    }

    public function test_integer_placeholder_handles_negative_values(): void
    {
        $this->assertSame('-17', $this->build()->parse('?i', -17));
    }

    public function test_double_placeholder_renders_as_float_literal(): void
    {
        $this->assertSame('3.14', $this->build()->parse('?d', 3.14));
    }

    public function test_double_placeholder_handles_scientific_notation(): void
    {
        $this->assertSame('1500', $this->build()->parse('?d', '1.5e3'));
    }

    // ── NULL handling ─────────────────────────────────────────────────

    public function test_null_value_renders_as_null_keyword_for_strings(): void
    {
        $this->assertSame('NULL', $this->build()->parse('?s', null));
    }

    public function test_null_value_renders_as_null_keyword_for_ints(): void
    {
        $this->assertSame('NULL', $this->build()->parse('?i', null));
    }

    public function test_null_value_renders_as_null_keyword_for_floats(): void
    {
        $this->assertSame('NULL', $this->build()->parse('?d', null));
    }

    // ── ?a: arrays ────────────────────────────────────────────────────

    public function test_array_placeholder_quotes_each_string_value(): void
    {
        $this->assertSame("('a','b','c')", $this->build()->parse('?a', ['a', 'b', 'c']));
    }

    public function test_array_placeholder_handles_mixed_types(): void
    {
        $this->assertSame("('a',1,2.5)", $this->build()->parse('?a', ['a', 1, 2.5]));
    }

    public function test_array_placeholder_handles_nulls_inside(): void
    {
        $this->assertSame("('a',NULL,'b')", $this->build()->parse('?a', ['a', null, 'b']));
    }

    public function test_array_placeholder_escapes_each_element(): void
    {
        $this->assertSame("('o''malley','foo')", $this->build()->parse('?a', ["o'malley", 'foo']));
    }

    public function test_empty_array_renders_as_empty_parens(): void
    {
        // SQL `IN ()` is invalid; the convention is for callers to avoid
        // an IN clause when their value set is empty. We don't change SQL
        // semantics here, just confirm what the strategy emits.
        $this->assertSame('()', $this->build()->parse('?a', []));
    }

    // ── compound parsing ─────────────────────────────────────────────

    public function test_compound_query_interpolates_all_placeholders(): void
    {
        $sql = $this->build()->parse(
            'INSERT INTO ?n (?n, ?n) VALUES (?s, ?i)',
            'users', 'name', 'age', 'Alice', '30'
        );
        $this->assertSame(
            'INSERT INTO "users" ("name", "age") VALUES (\'Alice\', 30)',
            $sql
        );
    }

    public function test_consecutive_placeholders_consume_args_in_order(): void
    {
        $sql = $this->build()->parse('?n=?s,?n=?s', 'a', 'one', 'b', 'two');
        $this->assertSame('"a"=\'one\',"b"=\'two\'', $sql);
    }

    public function test_no_placeholders_passes_query_through(): void
    {
        $this->assertSame('SELECT 1', $this->build()->parse('SELECT 1'));
    }

    public function test_missing_argument_throws_with_helpful_message(): void
    {
        try {
            $this->build()->parse('?n ?s', 'name');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('?s', $e->getMessage());
        }
    }

    public function test_extra_arguments_are_silently_ignored(): void
    {
        $this->assertSame('"x"', $this->build()->parse('?n', 'x', 'unused', 'also-unused'));
    }

    // ── query() behavior ─────────────────────────────────────────────

    public function test_query_returns_rows_for_select(): void
    {
        $db = $this->build();
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $db->query("INSERT INTO t (name) VALUES ('alpha')");
        $db->query("INSERT INTO t (name) VALUES ('beta')");

        $rows = $db->query('SELECT id, name FROM t ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertSame('alpha', $rows[0]['name']);
        $this->assertSame('beta', $rows[1]['name']);
    }

    public function test_query_returns_empty_array_for_select_with_no_rows(): void
    {
        $db = $this->build();
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->assertSame([], $db->query('SELECT * FROM t'));
    }

    public function test_query_returns_empty_for_create_table(): void
    {
        $this->assertSame([], $this->build()->query('CREATE TABLE t (id INTEGER PRIMARY KEY)'));
    }

    public function test_query_returns_empty_for_insert(): void
    {
        $db = $this->build();
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->assertSame([], $db->query('INSERT INTO t (id) VALUES (1)'));
    }

    public function test_invalid_query_raises_datastore_exception(): void
    {
        $this->expectException(DatastoreErrorException::class);
        $this->build()->query('SELECT NONEXISTENT FROM mistake WHERE');
    }

    public function test_querying_nonexistent_table_raises_exception(): void
    {
        $this->expectException(DatastoreErrorException::class);
        $this->build()->query('SELECT * FROM not_a_real_table');
    }

    public function test_exception_message_includes_query_for_debugging(): void
    {
        try {
            $this->build()->query('SELECT BAD SYNTAX');
            $this->fail('expected DatastoreErrorException');
        } catch (DatastoreErrorException $e) {
            $this->assertStringContainsString('SELECT BAD SYNTAX', $e->getMessage());
        }
    }

    // ── lastInsertId ──────────────────────────────────────────────────

    public function test_last_insert_id_returns_rowid(): void
    {
        $db = $this->build();
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->query("INSERT INTO t (name) VALUES ('a')");
        $db->query("INSERT INTO t (name) VALUES ('b')");
        $this->assertSame(2, $db->lastInsertId());
    }

    public function test_last_insert_id_returns_zero_before_any_insert(): void
    {
        $db = $this->build();
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->assertSame(0, $db->lastInsertId());
    }

    public function test_last_insert_id_is_per_connection(): void
    {
        $a = new PdoDatabaseStrategy(new Connection('sqlite::memory:'));
        $b = new PdoDatabaseStrategy(new Connection('sqlite::memory:'));

        $a->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $a->query('INSERT INTO t (id) VALUES (NULL)');
        $a->query('INSERT INTO t (id) VALUES (NULL)');

        $this->assertSame(2, $a->lastInsertId());
        $this->assertSame(0, $b->lastInsertId());
    }

    // ── round-trip safety ────────────────────────────────────────────

    public function test_hostile_input_round_trips_unchanged(): void
    {
        $db = $this->build();
        $db->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT)');

        $hostile = "'; DROP TABLE t; --";
        $sql = $db->parse('INSERT INTO ?n (content) VALUES (?s)', 't', $hostile);
        $db->query($sql);

        // If quoting were broken, the DROP would have fired and the next
        // SELECT would throw.
        $rows = $db->query('SELECT content FROM t');
        $this->assertCount(1, $rows);
        $this->assertSame($hostile, $rows[0]['content']);
    }
}
