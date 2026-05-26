<?php

namespace PHPNomad\SQLite\Integration\Facades;

use PHPNomad\Facade\Abstracts\Facade;
use PHPNomad\Singleton\Traits\WithInstance;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;

/**
 * Static facade matching the shape of mysql-integration's Database. Used
 * by the SQLite QueryBuilder and ClauseBuilder so they don't need explicit
 * DI plumbing for the parse step.
 */
class Database extends Facade
{
    use WithInstance;

    public static function parse(string $query, ...$args): string
    {
        return static::instance()->getContainedInstance()->parse($query, ...$args);
    }

    public static function query(string $query): array
    {
        return static::instance()->getContainedInstance()->query($query);
    }

    public static function lastInsertId(): int
    {
        return static::instance()->getContainedInstance()->lastInsertId();
    }

    protected function abstractInstance(): string
    {
        return DatabaseStrategy::class;
    }
}
