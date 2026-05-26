<?php

namespace PHPNomad\SQLite\Integration\Builders;

use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Traits\WithPrependedFields;
use PHPNomad\SQLite\Integration\Facades\Database;
use PHPNomad\Utils\Helpers\Arr;

/**
 * SQLite-flavored clause builder. Structurally identical to the MySQL one;
 * the only meaningful difference is which Database facade is used to parse
 * placeholder values.
 */
class SQLiteClauseBuilder implements ClauseBuilder
{
    use WithPrependedFields;

    protected array $clauses = [];
    protected array $preparedValues = [];
    protected array $validOperators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN',
        'NOT BETWEEN', 'IS NULL', 'IS NOT NULL',
    ];

    public function where($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values);
        return $this;
    }

    public function andWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'AND');
        return $this;
    }

    public function orWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'OR');
        return $this;
    }

    public function group(string $logic, ClauseBuilder ...$clauses)
    {
        $this->clauses[] = ['logic' => $logic, 'clauses' => $clauses];
        return $this;
    }

    public function andGroup(string $logic, ClauseBuilder ...$clauses)
    {
        if (! empty($this->clauses)) {
            $this->clauses[] = 'AND';
        }
        return $this->group($logic, ...$clauses);
    }

    public function orGroup(string $logic, ClauseBuilder ...$clauses)
    {
        if (! empty($this->clauses)) {
            $this->clauses[] = 'OR';
        }
        return $this->group($logic, ...$clauses);
    }

    protected function getFieldString($field): ?string
    {
        if (! is_array($field) && $this->tableHasField($field)) {
            return $this->prependField($field);
        }

        if (is_array($field)) {
            $str = Arr::process($field)
                ->filter(fn ($f) => $this->tableHasField($f))
                ->map(fn ($f) => $this->prependField($f))
                ->setSeparator(', ')
                ->toString();
            return $str !== '' ? "({$str})" : null;
        }

        return null;
    }

    protected function addCondition($field, string $operator, array $values, ?string $logic = null): self
    {
        $operator = strtoupper($operator);
        if (! in_array($operator, $this->validOperators, true)) {
            return $this;
        }

        $fieldStr = $this->getFieldString($field);

        // If the field isn't on the active table, drop the entire
        // predicate. (The mysql-integration ships with a latent bug here
        // — it pushes `null` for the field but still emits the operator
        // and placeholder, producing invalid SQL like `WHERE = 'x'`.)
        if ($fieldStr === null) {
            return $this;
        }

        $placeholder = $this->generatePlaceholder($field, $values, $operator);

        if (! empty($this->clauses) && $logic && in_array(strtoupper($logic), ['AND', 'OR'], true)) {
            $this->clauses[] = strtoupper($logic);
        }

        $this->clauses[] = $fieldStr;
        $this->clauses[] = $operator;
        $this->clauses[] = $placeholder;

        foreach (Arr::whereNotNull($values) as $value) {
            $this->preparedValues[] = $value;
        }

        return $this;
    }

    public function build(): string
    {
        $queryParts = [];
        $subQueryReplacements = [];
        $query = '';
        $marker = 0;

        foreach ($this->clauses as $clause) {
            if (is_string($clause)) {
                $queryParts[] = $clause;
            } elseif (is_array($clause) && isset($clause['logic'], $clause['clauses'])) {
                $groupParts = [];
                foreach ($clause['clauses'] as $groupClause) {
                    if ($groupClause instanceof ClauseBuilder) {
                        $marker++;
                        $key = '__NOMADIC_SUBQUERY__' . $marker;
                        $subQueryReplacements[$key] = $groupClause->build();
                        $groupParts[] = $key;
                    }
                }
                if (! empty($groupParts)) {
                    $queryParts[] = '(' . implode(" {$clause['logic']} ", $groupParts) . ')';
                }
            } elseif ($clause instanceof ClauseBuilder) {
                $marker++;
                $key = '__NOMADIC_SUBQUERY__' . $marker;
                $subQueryReplacements[$key] = $clause->build();
                $queryParts[] = $key;
            }
        }

        if (! empty($queryParts)) {
            $query = implode(' ', $queryParts);
            if (! empty($this->preparedValues)) {
                $query = Database::parse($query, ...$this->preparedValues);
            }
            foreach ($subQueryReplacements as $key => $subQuery) {
                $query = str_replace($key, $subQuery, $query);
            }
        }

        $this->reset();
        return $query;
    }

    public function reset()
    {
        $this->clauses = [];
        $this->preparedValues = [];
        return $this;
    }

    protected function generatePlaceholder($field, array $values, string $operator): string
    {
        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            return '';
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $placeholders = implode(',', array_fill(0, count($values), '?a'));
            return "({$placeholders})";
        }

        return '?s';
    }
}
