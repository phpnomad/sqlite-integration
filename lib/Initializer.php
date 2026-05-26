<?php

namespace PHPNomad\SQLite\Integration;

use PHPNomad\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use PHPNomad\Database\Interfaces\CanConvertToDatabaseDateString;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategy;
use PHPNomad\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategy;
use PHPNomad\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategy;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as CoreTableUpdateStrategy;
use PHPNomad\Di\Interfaces\CanSetContainer;
use PHPNomad\Di\Interfaces\HasBindings;
use PHPNomad\Di\Traits\HasSettableContainer;
use PHPNomad\Loader\Interfaces\HasClassDefinitions;
use PHPNomad\Loader\Interfaces\Loadable;
use PHPNomad\SQLite\Integration\Adapters\DatabaseDateAdapter;
use PHPNomad\SQLite\Integration\Builders\QueryBuilder;
use PHPNomad\SQLite\Integration\Builders\SQLiteClauseBuilder;
use PHPNomad\SQLite\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SQLite\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\SQLite\Integration\Strategies\QueryStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableCreateStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableDeleteStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableExistsStrategy;
use PHPNomad\SQLite\Integration\Strategies\TableUpdateStrategy;

class Initializer implements HasClassDefinitions, Loadable, CanSetContainer
{
    use HasSettableContainer;

    public function getClassDefinitions(): array
    {
        return [
            PdoDatabaseStrategy::class => DatabaseStrategy::class,
            QueryBuilder::class => CoreQueryBuilder::class,
            SQLiteClauseBuilder::class => ClauseBuilder::class,
            TableCreateStrategy::class => CoreTableCreateStrategy::class,
            TableDeleteStrategy::class => CoreTableDeleteStrategy::class,
            TableExistsStrategy::class => CoreTableExistsStrategy::class,
            TableUpdateStrategy::class => CoreTableUpdateStrategy::class,
            QueryStrategy::class => CoreQueryStrategy::class,
            DatabaseDateAdapter::class => [
                CanConvertDatabaseStringToDateTime::class,
                CanConvertToDatabaseDateString::class,
            ],
        ];
    }

    public function load(): void
    {
        if (! $this->container instanceof HasBindings) {
            return;
        }

        $this->container->bindFactory(
            Connection::class,
            fn () => new Connection(getenv('PHPNOMAD_SQLITE_DSN') ?: 'sqlite::memory:')
        );
    }
}
