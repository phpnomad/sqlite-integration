# phpnomad/sqlite-integration

[![Latest Version](https://img.shields.io/packagist/v/phpnomad/sqlite-integration.svg)](https://packagist.org/packages/phpnomad/sqlite-integration)
[![Total Downloads](https://img.shields.io/packagist/dt/phpnomad/sqlite-integration.svg)](https://packagist.org/packages/phpnomad/sqlite-integration)
[![PHP Version](https://img.shields.io/packagist/php-v/phpnomad/sqlite-integration.svg)](https://packagist.org/packages/phpnomad/sqlite-integration)
[![License](https://img.shields.io/packagist/l/phpnomad/sqlite-integration.svg)](https://packagist.org/packages/phpnomad/sqlite-integration)

`phpnomad/sqlite-integration` implements `phpnomad/db`'s query builders and table strategies for SQLite via PDO. It opens its own connection (configured by DSN), generates SQLite-flavored SQL, and exposes the same strategy interfaces that `phpnomad/mysql-integration` does — so entity classes, repositories, and validation are portable between MySQL and SQLite.

The intended use case is the **local-storage half of a desktop app** whose server uses `phpnomad/mysql-integration`. Same models on both sides, only the strategy binding differs.

## Installation

```bash
composer require phpnomad/sqlite-integration
```

## What This Provides

- A `QueryBuilder` and `ClauseBuilder` that emit SQLite-flavored SQL for SELECT, JOIN, GROUP BY, ORDER BY, LIMIT, OFFSET, and the full set of comparison operators (including IS NULL, IN, BETWEEN, and AND/OR groups)
- `QueryStrategy`, `TableCreateStrategy`, `TableDeleteStrategy`, `TableExistsStrategy`, and `TableUpdateStrategy` implementations covering insert, update, delete, query, estimated count, and schema management
- `TableUpdateStrategy::syncColumns` handles SQLite's limited ALTER — simple ADD/DROP COLUMN for the common case, transactional create-copy-drop-rename for affinity changes
- `PdoDatabaseStrategy` — a PDO-backed `DatabaseStrategy` that parses SafeMySQL-style placeholders (`?n`, `?s`, `?i`, `?d`, `?a`) so the builders speak the same language as the MySQL pipeline
- `Connection` — a thin PDO wrapper that turns on foreign keys and WAL journaling by default
- `DatabaseDateAdapter` that round-trips `DateTime` through the same `Y-m-d H:i:s` format the MySQL adapter uses
- A `Database` facade exposing `parse()`, `query()`, and `lastInsertId()` for the bound `DatabaseStrategy`

## Requirements

- PHP 8.3+ with `pdo` and `pdo_sqlite` extensions
- `phpnomad/db`
- `phpnomad/loader ^1.0 || ^2.0`

This package supplies its own `DatabaseStrategy` (`PdoDatabaseStrategy`) — unlike `phpnomad/mysql-integration`, you do not need a separate driver package.

## Usage

Add `Initializer` to your bootstrapper. The integration registers every builder, strategy, and adapter listed above.

```php
<?php

use PHPNomad\Di\Container\Container;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\SQLite\Integration\Initializer as SQLiteIntegration;

$container = new Container();

$bootstrapper = new Bootstrapper(
    $container,
    new SQLiteIntegration()
);

$bootstrapper->load();
```

By default the integration opens an in-memory database. Point it at a file by setting `PHPNOMAD_SQLITE_DSN`:

```bash
PHPNOMAD_SQLITE_DSN="sqlite:/var/lib/myapp/data.db" php bin/...
```

Handlers in `phpnomad/db` will then resolve `QueryStrategy`, `QueryBuilder`, `ClauseBuilder`, and the table strategies from this package.

## SQLite vs MySQL

The strategy layer is dialect-aware; everything above it is not. A few practical differences to know about:

- **No `AUTO_INCREMENT` keyword.** Use `INTEGER PRIMARY KEY` (rowid alias) or pass `AUTOINCREMENT` as a column attribute for strictly-monotonic ids. The MySQL `AUTO_INCREMENT` attribute is translated automatically.
- **Type affinity, not types.** `VARCHAR(255)` and `TEXT` are the same thing in SQLite. MySQL types (`BIGINT`, `LONGTEXT`, `DATETIME`, `BOOLEAN`, etc.) are mapped to SQLite-canonical equivalents in `CREATE TABLE`.
- **Limited `ALTER TABLE`.** Adding and dropping columns work directly. Changing a column's type affinity triggers a transactional rebuild handled by `TableUpdateStrategy`.
- **`LIKE` is case-insensitive by default for ASCII.** Set `PRAGMA case_sensitive_like = 1` if your app needs MySQL-like behavior.
- **No `ENGINE=InnoDB` / `CHARACTER SET=` table options.** Silently dropped.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

The test suite covers placeholder semantics, query construction, clause composition, table lifecycle, schema migration, value safety against SQL injection, type translation, and full-stack scenarios.

## Documentation

The `phpnomad/db` documentation at [phpnomad.com](https://phpnomad.com) covers table schemas, handlers, and how the query building pipeline fits together.

## License

MIT License. See [LICENSE.txt](LICENSE.txt).
