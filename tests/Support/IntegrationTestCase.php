<?php

namespace PHPNomad\SQLite\Integration\Tests\Support;

use PHPNomad\Di\Container\Container;
use PHPNomad\SQLite\Integration\Connection;
use PHPNomad\SQLite\Integration\Facades\Database;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SQLite\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\SQLite\Integration\Tests\TestCase;

/**
 * Shared setup for tests that need the static `Database` facade wired to a
 * live PDO connection (QueryBuilder, ClauseBuilder, QueryStrategy).
 *
 * Each test gets a fresh :memory: SQLite, so leaks between tests don't
 * matter and we never persist anything.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PdoDatabaseStrategy $db;

    protected function setUp(): void
    {
        $this->db = new PdoDatabaseStrategy(new Connection('sqlite::memory:'));

        $container = new Container();
        $container->bindFactory(DatabaseStrategy::class, fn () => $this->db);
        Database::instance()->setContainer($container);
    }
}
