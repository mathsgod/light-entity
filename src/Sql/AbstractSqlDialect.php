<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expression\AggregateExpression;
use Light\Linq\Expression\BinaryExpression;
use Light\Linq\Expression\ConstantExpression;
use Light\Linq\Expression\FieldExpression;
use Light\Linq\Expression\LogicalExpression;
use Light\Linq\Expression\ProjectionExpression;
use Light\Linq\Expression\ValueExpression;

abstract class AbstractSqlDialect implements SqlDialect
{
    /** @var array<string, mixed> */
    private array $parameters = [];
    private int $parameterIndex = 0;

    final public function compile(QueryPlan $query): CompiledQuery
    {
        $this->parameters = [];
        $this->parameterIndex = 0;

        $terminal = $query->terminal;
        if (in_array($terminal, ['sum', 'average', 'min', 'max'], true)) {
            $query = clone $query;
            if ($query->limit === null && $query->offset === null) $query->orderings = [];
            $query->terminal = 'sequence';
            $direct = $query->selection === null && $query->groupKey === null && !$query->distinct
                && $query->limit === null && $query->offset === null;
            $source = $direct ? null : $this->compileSequence($query);
            $operand = $this->value($query->aggregateSelector);
            // SQL Server AVG(integer) truncates fractional results.
            if ($terminal === 'average' && $this instanceof SqlServerDialect) {
                $operand = 'CAST('.$operand.' AS FLOAT)';
            }
            $function = $terminal === 'average' ? 'AVG' : strtoupper($terminal);
            $aggregate = $function.'('.$operand.')';
            if ($terminal === 'sum') $aggregate = 'COALESCE('.$aggregate.', 0)';
            $selection = $aggregate.' AS '.$this->quoteIdentifier('__linq_'.$terminal);
            $sql = $direct ? $this->compileSequence($query, $selection)
                : 'SELECT '.$selection.' FROM ('.$source.') AS '.$this->quoteIdentifier('__linq_source');
            return new CompiledQuery($sql, $this->parameters, $terminal);
        }
        $resultMode = match ($terminal) {
            'count' => 'count',
            'any' => 'any',
            'all', 'contains' => 'boolean',
            'firstOrDefault', 'single', 'singleOrDefault', 'last', 'lastOrDefault' => $terminal.'-'.($query->selection instanceof FieldExpression || $query->selection instanceof FunctionExpression || $query->selection instanceof AggregateExpression ? 'scalar' : 'row'),
            'first' => $query->selection instanceof FieldExpression || $query->selection instanceof FunctionExpression || $query->selection instanceof AggregateExpression ? 'first-scalar' : 'first-row',
            default => $query->selection instanceof FieldExpression || $query->selection instanceof FunctionExpression || $query->selection instanceof AggregateExpression ? 'scalar-list' : 'rows',
        };

        if (in_array($terminal, ['first', 'firstOrDefault', 'single', 'singleOrDefault', 'last', 'lastOrDefault'], true)) {
            $query = clone $query;
            $max = in_array($terminal, ['single', 'singleOrDefault'], true) ? 2 : 1;
            $query->limit = min($query->limit ?? $max, $max);
            $query->terminal = 'sequence';
        } elseif (in_array($terminal, ['count', 'any', 'all', 'contains'], true)
            && $query->limit === null
            && $query->offset === null
        ) {
            // Ordering cannot affect an unpaged aggregate and is illegal in a
            // SQL Server derived table unless TOP/OFFSET is also present.
            $query = clone $query;
            $query->orderings = [];
        }

        $sql = $this->compileSequence($query);
        if ($terminal === 'count' || $terminal === 'any') {
            $alias = $this->quoteIdentifier('__linq_source');
            $aggregate = $this->quoteIdentifier('__linq_count');
            $sql = "SELECT COUNT(*) AS {$aggregate} FROM ({$sql}) AS {$alias}";
        }

        if ($terminal === 'all' || $terminal === 'contains') {
            $predicate = $this->value($query->terminalPredicate);
            $condition = $terminal === 'all'
                ? '(CASE WHEN '.$predicate.' THEN 0 ELSE 1 END = 1)' : $predicate;
            $exists = $terminal === 'all' ? 'NOT EXISTS' : 'EXISTS';
            $sql = 'SELECT CASE WHEN '.$exists.' (SELECT 1 FROM ('.$sql.') AS '
                .$this->quoteIdentifier('__linq_source').' WHERE '.$condition.') THEN 1 ELSE 0 END AS '
                .$this->quoteIdentifier('__linq_boolean');
        }
        return new CompiledQuery($sql, $this->parameters, $resultMode, $query->defaultValue);
    }

    final public function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        foreach ($parts as &$part) {
            if ($part === '' || $part === '*') {
                throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
            }
            $part = $this->quoteIdentifierPart($part);
        }
        return implode('.', $parts);
    }

    abstract protected function quoteIdentifierPart(string $identifier): string;

    /** @return array{prefix: string, suffix: string} */
    abstract protected function pagination(?int $limit, ?int $offset, bool $hasOrderBy): array;

    private function compileSequence(QueryPlan $query, ?string $selectionOverride = null): string
    {
        $pagination = $this->pagination($query->limit, $query->offset, $query->orderings !== []);
        $selection = $selectionOverride ?? $this->selection($query->selection);

        $from = $query->sourcePlan === null
            ? ($query->rawSource === null ? $this->quoteIdentifier($query->table)
                : '('.(new RawSqlParser())->compile($query->rawSource, $this->parameter(...), $this)."\n) AS ".$this->quoteIdentifier('__raw_source'))
            : '('.$this->derivedSequence($query->sourcePlan).') AS '.$this->quoteIdentifier(
                $query->innerPlan === null ? '__linq_result' : 'outer',
            );
        if ($query->setLeft !== null) {
            $from = '('.$this->compileSet($query).') AS '.$this->quoteIdentifier('__linq_set');
        }
        $sql = 'SELECT '.($query->distinct ? 'DISTINCT ' : '').$pagination['prefix'].$selection.' FROM '.$from;
        if ($query->innerPlan !== null) {
            $sql .= ' '.$query->joinType.' JOIN ('.$this->derivedSequence($query->innerPlan)
                .') AS '.$this->quoteIdentifier('inner');
            if ($query->joinType !== 'CROSS') {
                $sql .= ' ON '.($query->collectionPredicate !== null
                    ? $this->value($query->collectionPredicate) : $this->joinValue($query->joinCondition));
            }
        }

        if ($query->predicates !== []) {
            $predicates = array_map(fn (ValueExpression $item): string => $this->value($item), $query->predicates);
            $sql .= ' WHERE '.implode(' AND ', $predicates);
        }

        if ($query->groupKey !== null) {
            $keys = $query->groupKey instanceof ProjectionExpression
                ? array_values($query->groupKey->members) : [$query->groupKey];
            $sql .= ' GROUP BY '.implode(', ', array_map($this->value(...), $keys));
        }
        if ($query->havingPredicates !== []) {
            $sql .= ' HAVING '.implode(' AND ', array_map($this->value(...), $query->havingPredicates));
        }
        if ($query->orderings !== []) {
            $parts = [];
            foreach ($query->orderings as $ordering) {
                $parts[] = $this->value($ordering['expression']).($ordering['descending'] ? ' DESC' : ' ASC');
            }
            $sql .= ' ORDER BY '.implode(', ', $parts);
        } elseif ($query->offset !== null && $this instanceof SqlServerDialect) {
            $sql .= ' ORDER BY (SELECT 0)';
        }

        return $sql.$pagination['suffix'];
    }

    private function compileSet(QueryPlan $query): string
    {
        $left = $this->derivedSequence($query->setLeft);
        $right = $this->derivedSequence($query->setRight);
        $leftAlias = $this->quoteIdentifier('__linq_left');
        $rightAlias = $this->quoteIdentifier('__linq_right');
        // EXISTS also supports MySQL installations without native INTERSECT/EXCEPT.
        if ($this instanceof MySqlDialect && in_array($query->setOperator, ['intersect', 'except'], true)) {
            $conditions = $columns = [];
            foreach ($query->setColumns as $name) {
                $column = $this->quoteIdentifier($name);
                $a = $leftAlias.'.'.$column;
                $b = $rightAlias.'.'.$column;
                $columns[] = $a.' AS '.$column;
                $conditions[] = '('.$a.' = '.$b.' OR ('.$a.' IS NULL AND '.$b.' IS NULL))';
            }
            $exists = $query->setOperator === 'except' ? 'NOT EXISTS' : 'EXISTS';
            return 'SELECT DISTINCT '.implode(', ', $columns).' FROM ('.$left.') AS '.$leftAlias
                .' WHERE '.$exists.' (SELECT 1 FROM ('.$right.') AS '.$rightAlias
                .' WHERE '.implode(' AND ', $conditions).')';
        }
        $operator = match ($query->setOperator) {
            'concat' => 'UNION ALL', 'union' => 'UNION',
            'intersect' => 'INTERSECT', 'except' => 'EXCEPT',
            default => throw new \LogicException('Unsupported set operator.'),
        };
        return 'SELECT * FROM ('.$left.') AS '.$leftAlias.' '.$operator
            .' SELECT * FROM ('.$right.') AS '.$rightAlias;
    }

    private function derivedSequence(QueryPlan $query): string
    {
        $query = clone $query;
        if ($query->limit === null && $query->offset === null) {
            $query->orderings = [];
        }
        return $this->compileSequence($query);
    }

    private function joinValue(ValueExpression $condition): string
    {
        // Unlike predicate null comparisons, JOIN keys use SQL equality even for null.
        if ($condition instanceof LogicalExpression) {
            return '('.$this->joinValue($condition->left).' AND '.$this->joinValue($condition->right).')';
        }
        if ($condition instanceof BinaryExpression) {
            return '('.$this->value($condition->left).' = '.$this->value($condition->right).')';
        }
        throw new \LogicException('Invalid join condition.');
    }

    private function selection(?ValueExpression $selection): string
    {
        if ($selection === null) {
            return '*';
        }
        if ($selection instanceof FieldExpression || $selection instanceof FunctionExpression || $selection instanceof AggregateExpression) {
            return $this->value($selection)
                .' AS '.$this->quoteIdentifier('__linq_value');
        }
        if ($selection instanceof ProjectionExpression) {
            $members = [];
            foreach ($selection->members as $alias => $expression) {
                $members[] = $this->value($expression).' AS '.$this->quoteIdentifier($alias);
            }
            return implode(', ', $members);
        }
        throw new \LogicException('Unsupported SQL selection expression: '.$selection::class);
    }

    private function value(ValueExpression $expression): string
    {
        if ($expression instanceof BinaryExpression
            && in_array($expression->operator, ['=', '!='], true)
            && ($expression->left instanceof ConstantExpression && $expression->left->value === null
                || $expression->right instanceof ConstantExpression && $expression->right->value === null)
        ) {
            $operand = $expression->left instanceof ConstantExpression
                ? $expression->right
                : $expression->left;
            return '('.$this->value($operand)
                .($expression->operator === '=' ? ' IS NULL)' : ' IS NOT NULL)');
        }

        return match (true) {
            $expression instanceof AggregateExpression => strtoupper($expression->function).'('.(
                $expression->operand === null ? '*' : $this->value($expression->operand)).')',
            $expression instanceof FieldExpression => $this->quoteIdentifier($expression->path),
            $expression instanceof NotExpression => '(NOT '.$this->value($expression->operand).')',
            $expression instanceof ConstantExpression => $this->parameter($expression->value),
            $expression instanceof FunctionExpression => $this->functionName($expression->definition)
                .'('.implode(', ', array_map($this->value(...), $expression->arguments)).')',
            $expression instanceof BinaryExpression => '('.$this->value($expression->left).' '
                .$expression->operator.' '.$this->value($expression->right).')',
            $expression instanceof LogicalExpression => '('.$this->value($expression->left).' '
                .strtoupper($expression->operator).' '.$this->value($expression->right).')',
            $expression instanceof ProjectionExpression => throw new \LogicException(
                'Nested projection expressions are not supported.',
            ),
            default => throw new \LogicException('Unsupported SQL value expression: '.$expression::class),
        };
    }

    /** Dialects can restrict or customize scalar function naming. */
    protected function functionName(SqlFunctionDefinition $function): string
    {
        return $this->quoteIdentifier(($function->schema === null ? '' : $function->schema.'.').$function->name);
    }

    protected function parameterSql(string $placeholder, mixed $value): string
    {
        return $placeholder;
    }

    private function parameter(mixed $value): string
    {
        $name = 'p'.$this->parameterIndex++;
        $this->parameters[$name] = $value;
        return $this->parameterSql(':'.$name, $value);
    }
}
