<?php

namespace PHPNomad\SQLite\Integration\Builders;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder as QueryBuilderInterface;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Traits\WithPrependedFields;
use PHPNomad\SQLite\Integration\Facades\Database;
use PHPNomad\Utils\Helpers\Arr;

/**
 * SQLite QueryBuilder. Mirrors the MySQL one closely — SELECT/JOIN/GROUP/
 * ORDER/LIMIT/OFFSET syntax is largely identical between dialects. The only
 * substantive difference is which Database facade is used to parse values
 * at build time.
 */
class QueryBuilder implements QueryBuilderInterface
{
    use WithPrependedFields;

    protected array $select = [];
    protected array $from = [];
    protected array $sql = [];
    private array $prepare = [];
    protected array $join = [];
    protected array $items = [];
    protected array $operands = [];
    protected array $limit = [];
    protected array $offset = [];
    protected array $orderBy = [];
    protected ?ClauseBuilder $clauseBuilder = null;
    protected array $groupBy = [];

    public function select(string $field, string ...$fields)
    {
        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        if ($field === '*') {
            $this->select[] = '*';
            return $this;
        }

        $this->select[] = Arr::process(Arr::merge([$field], $fields))
            ->each(fn (string $f) => $this->prependField($f))
            ->toString();

        return $this;
    }

    public function from(Table $table)
    {
        $this->useTable($table);
        $this->from = ['FROM', $table->getName(), 'AS', $table->getAlias()];
        return $this;
    }

    public function where(?ClauseBuilder $clauseBuilder)
    {
        $this->clauseBuilder = $clauseBuilder->useTable($this->table);
        return $this;
    }

    public function leftJoin(Table $table, string $column, string $onColumn)
    {
        $join = [
            'LEFT JOIN', $table->getName(), 'AS', $table->getAlias(), 'ON',
            $this->prependField($column), '=', $this->prependField($onColumn, $table),
        ];
        $this->join = empty($this->join) ? $join : Arr::merge($this->join, $join);
        return $this;
    }

    public function rightJoin(Table $table, string $column, string $onColumn)
    {
        // SQLite supports RIGHT JOIN as of 3.39 (June 2022). For earlier
        // versions you'd swap sides and use LEFT JOIN. Modern PHP+pdo_sqlite
        // bundles are >= 3.39 so we emit the natural form.
        $join = [
            'RIGHT JOIN', $table->getName(), 'AS', $table->getAlias(), 'ON',
            $this->prependField($column), '=', $this->prependField($onColumn, $table),
        ];
        $this->join = empty($this->join) ? $join : Arr::merge($this->join, $join);
        return $this;
    }

    public function groupBy(string $column, string ...$columns)
    {
        foreach (Arr::merge([$column], $columns) as $col) {
            if (empty($this->groupBy)) {
                $this->groupBy = ['GROUP BY', $this->prependField($col)];
            } else {
                $this->groupBy[] = ',';
                $this->groupBy[] = $this->prependField($col);
            }
        }
        return $this;
    }

    public function sum(string $fieldToSum, ?string $alias = null)
    {
        $alias = $alias ?: $fieldToSum . '_sum';
        $select = ['SUM(' . $this->prependField($fieldToSum) . ')', 'as', $alias];

        if (count($this->select) > 1) {
            array_unshift($select, ',');
        }
        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        $this->select = array_merge($this->select, $select);
        return $this;
    }

    public function count(string $fieldToCount, ?string $alias = null)
    {
        // Default alias is "<field>_count", with a fallback for '*' since
        // `*_count` isn't a valid SQL identifier. (The mysql-integration
        // has this same latent bug; the SQLite port fixes it.)
        if ($alias === null) {
            $alias = $fieldToCount === '*' ? 'count' : $fieldToCount . '_count';
        }
        if ($fieldToCount !== '*') {
            $fieldToCount = $this->prependField($fieldToCount);
        }
        $select = ['COUNT(' . $fieldToCount . ')', 'as', $alias];

        if (count($this->select) > 1) {
            array_unshift($select, ',');
        }
        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        $this->select = array_merge($this->select, $select);
        return $this;
    }

    public function limit(int $limit)
    {
        $this->limit = ['LIMIT', $limit];
        return $this;
    }

    public function offset(int $offset)
    {
        $this->offset = ['OFFSET', $offset];
        return $this;
    }

    public function orderBy(string $field, string $order)
    {
        $order = strtoupper($order);
        if (! in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'ASC';
        }
        $this->orderBy = ['ORDER BY', $this->prependField($field), $order];
        return $this;
    }

    public function build(): string
    {
        if (empty($this->select)) {
            $this->reset();
            throw new QueryBuilderException('Missing select field');
        }
        if (empty($this->from)) {
            $this->reset();
            throw new QueryBuilderException('Missing from field');
        }

        $this->sql = Arr::merge($this->select, $this->from);
        $this->maybeAppend('join');

        if ($this->clauseBuilder !== null) {
            $whereClause = $this->clauseBuilder->build();
            if (! empty($whereClause)) {
                $this->sql[] = 'WHERE ' . $whereClause;
            }
        }

        $this->maybeAppend('groupBy');
        $this->maybeAppend('orderBy');
        $this->maybeAppend('limit');
        $this->maybeAppend('offset');

        $sql = implode(' ', $this->sql);

        if (! empty($this->prepare)) {
            $sql = Database::parse($sql, ...$this->prepare);
        }

        $this->reset();
        return $sql;
    }

    public function reset()
    {
        $this->select = [];
        $this->clauseBuilder = null;
        $this->from = [];
        $this->sql = [];
        $this->prepare = [];
        $this->join = [];
        $this->items = [];
        $this->operands = [];
        $this->limit = [];
        $this->offset = [];
        $this->orderBy = [];
        $this->groupBy = [];
        return $this;
    }

    public function resetClauses(string $clause, string ...$clauses)
    {
        $clauses[] = $clause;
        foreach ($clauses as $c) {
            if (isset($this->$c)) {
                $this->$c = [];
            }
        }
        return $this;
    }

    private function maybeAppend(string $key): void
    {
        if (isset($this->$key) && is_array($this->$key)) {
            foreach ($this->$key as $id => $value) {
                if (is_array($value)) {
                    $this->prepare[] = $value['value'];
                    $this->$key[$id] = $value['type'];
                }
            }
            $this->sql = array_merge($this->sql, array_values($this->$key));
        }
    }
}
