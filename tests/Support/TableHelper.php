<?php

namespace PHPNomad\SQLite\Integration\Tests\Support;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;

/**
 * Helpers for building Table/Column fixtures in tests. Mirrors the shape
 * an app would build through phpnomad/db's Table abstract class, but
 * without the full registration ceremony.
 */
class TableHelper
{
    /**
     * @param array<int, Column> $columns
     * @param array<int, Index> $indices
     */
    public static function table(
        string $name,
        array $columns,
        array $indices = [],
        string $alias = '',
    ): Table {
        $alias = $alias !== '' ? $alias : substr($name, 0, 1);

        return new class($name, $alias, $columns, $indices) implements Table {
            public function __construct(
                private string $name,
                private string $alias,
                private array $columns,
                private array $indices,
            ) {}
            public function getName(): string { return $this->name; }
            public function getAlias(): string { return $this->alias; }
            public function getTableVersion(): string { return '1'; }
            public function getColumns(): array { return $this->columns; }
            public function getIndices(): array { return $this->indices; }
            public function getCharset(): ?string { return null; }
            public function getCollation(): ?string { return null; }
            public function getFieldsForIdentity(): array { return ['id']; }
            public function getUnprefixedName(): string { return $this->name; }
            public function getSingularUnprefixedName(): string { return rtrim($this->name, 's'); }
        };
    }

    /**
     * @param array<int, string> $attributes
     * @param array<int, string|int> $typeArgs
     */
    public static function column(
        string $name,
        string $type,
        array $attributes = [],
        array $typeArgs = [],
    ): Column {
        return new Column($name, $type, $typeArgs ?: null, ...$attributes);
    }
}
