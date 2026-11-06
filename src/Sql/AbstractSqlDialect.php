<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\{CoalesceExpression, ConditionalExpression};

use Light\Linq\Expressions\AggregateExpression;
use Light\Linq\Expressions\BinaryExpression;
use Light\Linq\Expressions\ConstantExpression;
use Light\Linq\Expressions\FieldExpression;
use Light\Linq\Expressions\LogicalExpression;
use Light\Linq\Expressions\ProjectionExpression;
use Light\Linq\Expressions\ValueExpression;

abstract class AbstractSqlDialect implements SqlDialect
{
    /** @var array<string, mixed> */
    private array $parameters = [];
    private int $parameterIndex = 0;
    private bool $qualifyFields = false;
    private int $aliasIndex = 0;
    private ?string $currentAlias = null;
    /** @var list<string|null> Lexical EXISTS scopes, nearest first. */
    private array $outerScopes = [];

    final public function compile(QueryPlan $query): CompiledQuery
    {
        $this->parameters = [];
        $this->parameterIndex = 0;
        $this->aliasIndex = 0;
        $this->currentAlias = null;
        $this->outerScopes = [];
        $this->qualifyFields = $this->hasOuterFields($query);

        $terminal = $query->terminal;
        if (in_array($terminal, ['update', 'delete'], true)) {
            $table = $this->quoteIdentifier($query->table);
            if ($this->qualifyFields) { $this->currentAlias = $query->table; }
            if ($terminal === 'update') {
                $assignments = [];
                foreach ($query->assignments as $name => $value) {
                    $assignments[] = $this->quoteIdentifier($name).' = '.$this->value($value);
                }
                $sql = 'UPDATE '.$table.' SET '.implode(', ', $assignments);
            } else {
                $sql = 'DELETE FROM '.$table;
            }
            if ($query->predicates !== []) {
                $sql .= ' WHERE '.implode(' AND ', array_map($this->value(...), $query->predicates));
            }
            return new CompiledQuery($sql, $this->parameters, 'affected');
        }

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
            'firstOrDefault', 'single', 'singleOrDefault', 'last', 'lastOrDefault' => $terminal.'-'.($this->isScalarSelection($query->selection) ? 'scalar' : 'row'),
            'first' => $this->isScalarSelection($query->selection) ? 'first-scalar' : 'first-row',
            default => $this->isScalarSelection($query->selection) ? 'scalar-list' : 'rows',
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
            $this->currentAlias = $this->qualifyFields ? '__linq_source' : null;
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

    private function hasOuterFields(mixed $value): bool
    {
        if ($value instanceof \Light\Linq\Expressions\OuterFieldExpression) { return true; }
        if ($value instanceof QueryPlan || $value instanceof \Light\Linq\Expressions\Expression) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $child) { if ($this->hasOuterFields($child)) { return true; } }
        }
        return false;
    }

    private function compileSequence(QueryPlan $query, ?string $selectionOverride = null): string
    {
        $previous = $this->currentAlias;
        $this->currentAlias = $this->qualifyFields
            ? ($query->innerPlan !== null ? 'outer' : '__q'.$this->aliasIndex++) : null;
        try { return $this->compileSequenceBody($query, $selectionOverride); }
        finally { $this->currentAlias = $previous; }
    }

    private function compileSequenceBody(QueryPlan $query, ?string $selectionOverride = null): string
    {
        $pagination = $this->pagination($query->limit, $query->offset, $query->orderings !== []);
        $selection = $selectionOverride ?? $this->selection($query->selection);

        $from = $query->sourcePlan === null
            ? ($query->rawSource === null ? $this->quoteIdentifier($query->table)
                .($this->currentAlias === null ? '' : ' AS '.$this->quoteIdentifier($this->currentAlias))
                : '('.(new RawSqlParser())->compile($query->rawSource, $this->parameter(...), $this)."\n) AS ".$this->quoteIdentifier($this->currentAlias ?? '__raw_source'))
            : '('.$this->derivedSequence($query->sourcePlan).') AS '.$this->quoteIdentifier(
                $this->currentAlias ?? ($query->innerPlan === null ? '__linq_result' : 'outer'),
            );
        if ($query->setLeft !== null) {
            $from = '('.$this->compileSet($query).') AS '.$this->quoteIdentifier($this->currentAlias ?? '__linq_set');
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
        if ($this->isScalarSelection($selection)) {
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
            $expression instanceof FieldExpression => $this->quoteIdentifier(
                $this->currentAlias !== null && !str_contains($expression->path, '.')
                    ? $this->currentAlias.'.'.$expression->path : $expression->path,
            ),
            $expression instanceof \Light\Linq\Expressions\OuterFieldExpression => $this->outerField($expression),
            $expression instanceof ExistsExpression => $this->exists($expression),
            $expression instanceof InExpression => '('.$this->value($expression->item)
                .($expression->negated ? ' NOT IN (' : ' IN (')
                .$this->derivedSequence((new SqlQueryCompiler($this))->subqueryPlan($expression->subquery)).'))',
            $expression instanceof NotExpression => '(NOT '.$this->value($expression->operand).')',
            // Newline keeps a trailing line comment inside the expression's parentheses.
            $expression instanceof RawSqlPredicateExpression, $expression instanceof RawSqlValueExpression => '('.(new RawSqlParser())->compile($expression, $this->parameter(...), $this)."\n)",
            $expression instanceof CoalesceExpression => 'COALESCE('.$this->value($expression->left).', '.$this->value($expression->right).')',
            $expression instanceof ConditionalExpression => '(CASE WHEN '.$this->value($expression->test)
                .' THEN '.$this->value($expression->ifTrue).' ELSE '.$this->value($expression->ifFalse).' END)',
            $expression instanceof ConstantExpression => $this->parameter($expression->value),
            $expression instanceof FunctionExpression => $this->functionName($expression->definition)
                .'('.implode(', ', array_map($this->value(...), $expression->arguments)).')',
            $expression instanceof LikeExpression => '('.$this->value($expression->match).' LIKE '
                .$this->value($expression->pattern)
                .($expression->escape === null ? '' : ' ESCAPE '.$this->parameter($expression->escape)).')',
            $expression instanceof StringPatternExpression => $this->stringPattern($expression),
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

    private function stringPattern(StringPatternExpression $expression): string
    {
        if ($expression->needle instanceof ConstantExpression && is_string($expression->needle->value)) {
            $pattern = strtr($expression->needle->value, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
            return $this->parameter(
                ($expression->operation === 'startsWith' ? '' : '%').$pattern
                .($expression->operation === 'endsWith' ? '' : '%'));
        }
        $needle = $this->value($expression->needle);
        foreach (['!' => '!!', '%' => '!%', '_' => '!_', '[' => '!['] as $from => $to) {
            $needle = 'REPLACE('.$needle.', '.$this->parameter($from).', '.$this->parameter($to).')';
        }
        $parts = [];
        if ($expression->operation !== 'startsWith') { $parts[] = $this->parameter('%'); }
        $parts[] = $needle;
        if ($expression->operation !== 'endsWith') { $parts[] = $this->parameter('%'); }
        return $this instanceof MySqlDialect ? 'CONCAT('.implode(', ', $parts).')'
            : '('.implode($this instanceof SqlServerDialect ? ' + ' : ' || ', $parts).')';
    }

    private function outerField(\Light\Linq\Expressions\OuterFieldExpression $field): string
    {
        $alias = $this->outerScopes[$field->depth - 1] ?? null;
        if ($alias === null) { throw new \LogicException('Outer fields require a matching enclosing EXISTS scope.'); }
        return $this->quoteIdentifier($alias.'.'.$field->path);
    }

    private function exists(ExistsExpression $expression): string
    {
        $previous = $this->outerScopes;
        $this->outerScopes = [$this->currentAlias, ...$previous];
        try {
            $plan = (new SqlQueryCompiler($this))->existsPlan($expression->subquery);
            return 'EXISTS ('.$this->derivedSequence($plan).')';
        } finally { $this->outerScopes = $previous; }
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
    private function isScalarSelection(?ValueExpression $selection): bool
    {
        return $selection instanceof FieldExpression || $selection instanceof FunctionExpression
            || $selection instanceof AggregateExpression || $selection instanceof CoalesceExpression
            || $selection instanceof ConditionalExpression;
    }

}
