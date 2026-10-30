<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

use PhpLinq\Expression\BinaryExpression;
use PhpLinq\Expression\ConstantExpression;
use PhpLinq\Expression\FieldExpression;
use PhpLinq\Expression\LogicalExpression;
use PhpLinq\Expression\ProjectionExpression;
use PhpLinq\Expression\ValueExpression;

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
        $resultMode = match ($terminal) {
            'count' => 'count',
            'any' => 'any',
            'first' => $query->selection instanceof FieldExpression ? 'first-scalar' : 'first-row',
            default => $query->selection instanceof FieldExpression ? 'scalar-list' : 'rows',
        };

        if ($terminal === 'first') {
            $query = clone $query;
            $query->limit = min($query->limit ?? 1, 1);
            $query->terminal = 'sequence';
        } elseif (($terminal === 'count' || $terminal === 'any')
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

        return new CompiledQuery($sql, $this->parameters, $resultMode);
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

    private function compileSequence(QueryPlan $query): string
    {
        $pagination = $this->pagination($query->limit, $query->offset, $query->orderings !== []);
        $selection = $this->selection($query->selection);

        $from = $query->sourcePlan === null
            ? $this->quoteIdentifier($query->table)
            : '('.$this->derivedSequence($query->sourcePlan).') AS '.$this->quoteIdentifier(
                $query->innerPlan === null ? '__linq_result' : 'outer',
            );
        $sql = 'SELECT '.$pagination['prefix'].$selection.' FROM '.$from;
        if ($query->innerPlan !== null) {
            $sql .= ' '.$query->joinType.' JOIN ('.$this->derivedSequence($query->innerPlan)
                .') AS '.$this->quoteIdentifier('inner').' ON '.$this->joinValue($query->joinCondition);
        }

        if ($query->predicates !== []) {
            $predicates = array_map(fn (ValueExpression $item): string => $this->value($item), $query->predicates);
            $sql .= ' WHERE '.implode(' AND ', $predicates);
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
        if ($selection instanceof FieldExpression) {
            return $this->quoteIdentifier($selection->path)
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
            $expression instanceof FieldExpression => $this->quoteIdentifier($expression->path),
            $expression instanceof ConstantExpression => $this->parameter($expression->value),
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

    private function parameter(mixed $value): string
    {
        $name = 'p'.$this->parameterIndex++;
        $this->parameters[$name] = $value;
        return ':'.$name;
    }
}
