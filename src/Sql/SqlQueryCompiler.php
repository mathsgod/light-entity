<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expression\Expression;
use Light\Linq\Expression\AggregateExpression;
use Light\Linq\Expression\GroupKeyExpression;
use Light\Linq\Expression\BinaryExpression;
use Light\Linq\Expression\ConstantExpression;
use Light\Linq\Expression\FieldExpression;
use Light\Linq\Expression\LogicalExpression;
use Light\Linq\Expression\ProjectionExpression;
use Light\Linq\Expression\ValueExpression;
use Light\Linq\Expression\MethodCallExpression;
use Light\Linq\Expression\SourceExpression;

final readonly class SqlQueryCompiler
{
    public function __construct(private SqlDialect $dialect)
    {
    }

    public function compile(Expression $expression): CompiledQuery
    {
        $plan = $this->plan($expression);
        $this->validatePlan($plan);
        return $this->dialect->compile($plan);
    }

    private function plan(Expression $expression): QueryPlan
    {
        if ($expression instanceof MethodCallExpression
            && in_array($expression->method, ['firstOrDefault', 'single', 'singleOrDefault', 'last', 'lastOrDefault', 'all', 'contains', 'sum', 'average', 'min', 'max'], true)) {
            return $this->terminalPlan($expression);
        }
        $operations = [];
        while ($expression instanceof MethodCallExpression) {
            array_unshift($operations, $expression);
            $expression = $expression->source;
        }
        if ($expression instanceof RawSqlSourceExpression) {
            $plan = new QueryPlan('__raw_source');
            $plan->rawSource = $expression;
        } elseif ($expression instanceof SourceExpression) {
            $plan = new QueryPlan($expression->name);
        } else {
            throw new \LogicException('A SQL query must start with a named or raw SQL source.');
        }
        $paginationStarted = false;
        $joinedProjection = false;
        $previousMethod = null;
        foreach ($operations as $operation) {
            if ($operation->method === 'thenBy' && !in_array($previousMethod, ['orderBy', 'thenBy'], true)) {
                throw new \LogicException('thenBy() requires a preceding orderBy().');
            }
            $previousMethod = $operation->method;
            if (in_array($operation->method, ['concat', 'union', 'intersect', 'except'], true)) {
                $plan = $this->setPlan($plan, $this->plan($operation->arguments['second']), $operation->method);
                $paginationStarted = $joinedProjection = false;
                continue;
            }


            if ($plan->setLeft !== null && !$plan->selection instanceof ProjectionExpression
                && in_array($operation->method, ['where', 'select', 'orderBy'], true)) {
                throw new \LogicException('Use named projections for field-based operators after a scalar set operation.');
            }
            // A projection after grouping changes the element type to an ordinary row.
            // DISTINCT before a new projection, and pagination before DISTINCT/GROUP BY,
            // must also remain inside a derived table.
            if (($plan->groupKey !== null && $plan->selection !== null
                    && !in_array($operation->method, ['count', 'any', 'first', 'skip', 'take', 'distinct'], true))
                || ($plan->distinct && in_array($operation->method, ['select', 'groupBy', 'where', 'orderBy'], true))
                || ($joinedProjection && $operation->method === 'groupBy')
                || ($paginationStarted && in_array($operation->method, ['distinct', 'groupBy'], true))
                || ($operation->method === 'groupBy' && $plan->selection !== null)) {
                $scalar = $plan->selection instanceof FieldExpression || $plan->selection instanceof FunctionExpression
                    || $plan->selection instanceof AggregateExpression;
                if ($scalar && $operation->method !== 'distinct') {
                    throw new \LogicException('Use a named projection before composing field-based operators.');
                }
                $wrapped = new QueryPlan('__linq_result');
                $wrapped->sourcePlan = $plan;
                if ($scalar) $wrapped->selection = new FieldExpression('__linq_value');
                $plan = $wrapped;
                $paginationStarted = $joinedProjection = false;
            }
            if ($operation->method === 'groupBy') {
                if ($plan->groupKey !== null) throw new \LogicException('Nested grouping is not supported.');
                $plan->groupKey = $operation->arguments['keySelector'];
                $this->resolveGrouped($plan->groupKey, null, true);
                $plan->orderings = [];
                continue;
            }
            if ($operation->method === 'distinct') {
                $plan->distinct = true;
                $plan->orderings = [];
                continue;
            }
            if ($paginationStarted && $plan->groupKey !== null && in_array($operation->method, ['where', 'orderBy'], true)) {
                throw new \LogicException('where/orderBy after skip/take requires subquery support.');
            }
            if ($plan->groupKey !== null && in_array($operation->method, ['where', 'select', 'orderBy', 'thenBy'], true)) {
                $argument = match ($operation->method) {
                    'where' => 'predicate', 'select' => 'selector', 'orderBy', 'thenBy' => 'keySelector',
                };
                $value = $this->resolveGrouped($operation->arguments[$argument], $plan->groupKey);
                if ($operation->method === 'orderBy') $plan->orderings = [];
                if ($operation->method === 'where') $plan->havingPredicates[] = $value;
                elseif ($operation->method === 'select') $plan->selection = $value;
                else $plan->orderings[] = ['expression' => $value, 'descending' => $operation->arguments['descending']];
                continue;
            }

            if (in_array($operation->method, ['join', 'leftJoin', 'selectMany'], true)) {
                $inner = $this->plan($operation->arguments['inner']);
                if ($plan->selection instanceof FieldExpression || $plan->selection instanceof FunctionExpression
                    || $plan->selection instanceof AggregateExpression
                    || $inner->selection instanceof FieldExpression || $inner->selection instanceof FunctionExpression
                    || $inner->selection instanceof AggregateExpression) {
                    throw new \LogicException('Join sources must return rows; use a named projection instead of a scalar selection.');
                }
                $joined = new QueryPlan('__linq_outer');
                $joined->sourcePlan = $plan;
                $joined->innerPlan = $inner;
                if ($operation->method === 'selectMany') {
                    $joined->collectionPredicate = $operation->arguments['predicate'];
                    $joined->joinType = $joined->collectionPredicate === null ? 'CROSS' : 'INNER';
                } else {
                    $joined->joinType = $operation->method === 'leftJoin' ? 'LEFT' : 'INNER';
                    $joined->joinCondition = $this->joinCondition(
                        $operation->arguments['outerKey'], $operation->arguments['innerKey'],
                    );
                }
                $joined->selection = $operation->arguments['resultSelector'];
                $plan = $joined;
                $paginationStarted = false;
                $joinedProjection = true;
                continue;
            }
            // Subsequent operators address the projected JOIN result, not its sources.
            if ($joinedProjection && in_array($operation->method, ['where', 'orderBy', 'select'], true)) {
                if ($plan->selection instanceof FieldExpression || $plan->selection instanceof FunctionExpression) {
                    throw new \LogicException('Use a named JOIN projection before composing field-based operators.');
                }
                $wrapped = new QueryPlan('__linq_result');
                $wrapped->sourcePlan = $plan;
                $plan = $wrapped;
                $paginationStarted = false;
                $joinedProjection = false;
            }
            if ($paginationStarted && in_array($operation->method, ['where', 'orderBy'], true)) {
                throw new \LogicException('where/orderBy after skip/take requires subquery support.');
            }

            if ($operation->method === 'orderBy') $plan->orderings = [];
            match ($operation->method) {
                'where' => $plan->predicates[] = $operation->arguments['predicate'],
                'select' => $plan->selection = $operation->arguments['selector'],
                'orderBy', 'thenBy' => $plan->orderings[] = [
                    'expression' => $operation->arguments['keySelector'],
                    'descending' => $operation->arguments['descending'],
                ],
                'skip' => $plan->offset = ($plan->offset ?? 0) + $operation->arguments['count'],
                'take' => $plan->limit = min($plan->limit ?? PHP_INT_MAX, $operation->arguments['count']),
                'count', 'any', 'first' => $plan->terminal = $operation->method,
                default => throw new \LogicException("Unsupported SQL query method: {$operation->method}"),
            };
            $paginationStarted = $paginationStarted || in_array($operation->method, ['skip', 'take'], true);
        }

        if ($plan->groupKey !== null && $plan->selection === null && in_array($plan->terminal, ['count', 'any'], true)) {
            $plan->selection = $plan->groupKey;
        }
        return $plan;
    }

    private function setPlan(QueryPlan $left, QueryPlan $right, string $method): QueryPlan
    {
        $leftColumns = $this->setColumns($left->selection);
        $rightColumns = $this->setColumns($right->selection);
        $leftScalar = !$left->selection instanceof ProjectionExpression;
        $rightScalar = !$right->selection instanceof ProjectionExpression;
        if ($leftColumns !== $rightColumns || $leftScalar !== $rightScalar) {
            throw new \LogicException('Set sources require matching projection names, order and scalar/row shape.');
        }
        $plan = new QueryPlan('__linq_set');
        $plan->setLeft = $left;
        $plan->setRight = $right;
        $plan->setOperator = $method;
        $plan->setColumns = $leftColumns;
        $plan->selection = $leftScalar ? new FieldExpression('__linq_value')
            : new ProjectionExpression(array_combine($leftColumns,
                array_map(static fn ($name) => new FieldExpression($name), $leftColumns)));
        return $plan;
    }

    /** @return non-empty-list<string> */
    private function setColumns(?ValueExpression $selection): array
    {
        if ($selection instanceof ProjectionExpression) return array_keys($selection->members);
        if ($selection instanceof FieldExpression || $selection instanceof FunctionExpression || $selection instanceof AggregateExpression) {
            return ['__linq_value'];
        }
        throw new \LogicException('SQL set operations require explicit scalar or named projections on both sources.');
    }

    private function terminalPlan(MethodCallExpression $expression): QueryPlan
    {
        $plan = $this->plan($expression->source);
        $method = $expression->method;
        $predicate = $expression->arguments['predicate'] ?? null;
        if (in_array($method, ['sum', 'average', 'min', 'max'], true)) {
            if ($plan->selection !== null && !$plan->selection instanceof ProjectionExpression) {
                throw new \LogicException('Use a named projection before a terminal aggregate.');
            }
            $selector = $expression->arguments['selector'];
            $this->validateValue($selector, false);
            if ($selector instanceof ProjectionExpression) {
                throw new \LogicException('Terminal aggregates require a scalar selector.');
            }
            $plan->aggregateSelector = $selector;
        } elseif ($method === 'contains') {
            if (!$plan->selection instanceof FieldExpression && !$plan->selection instanceof FunctionExpression
                && !$plan->selection instanceof AggregateExpression) {
                throw new \LogicException('contains() requires a scalar projection.');
            }
            $value = $expression->arguments['value'];
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('SQL contains() accepts scalar values or null.');
            }
            $plan->terminalPredicate = new BinaryExpression(new FieldExpression('__linq_value'), '=', new ConstantExpression($value));
        } elseif ($method === 'all') {
            $plan->terminalPredicate = $predicate;
        } elseif ($predicate !== null) {
            if ($plan->groupKey !== null && $plan->selection === null && $plan->limit === null && $plan->offset === null) {
                $plan->havingPredicates[] = $this->resolveGrouped($predicate, $plan->groupKey);
            } elseif ($plan->selection !== null || $plan->limit !== null || $plan->offset !== null || $plan->innerPlan !== null) {
                if ($plan->selection instanceof FieldExpression || $plan->selection instanceof FunctionExpression
                    || $plan->selection instanceof AggregateExpression) {
                    throw new \LogicException('Use a named projection before a field-based terminal predicate.');
                }
                $wrapped = new QueryPlan('__linq_result');
                $wrapped->sourcePlan = $plan;
                $wrapped->predicates[] = $predicate;
                foreach ($plan->orderings as $ordering) {
                    $wrapped->orderings[] = [
                        'expression' => $this->projectedOrdering($ordering['expression'], $plan->selection),
                        'descending' => $ordering['descending'],
                    ];
                }
                $plan = $wrapped;
            } else {
                $plan->predicates[] = $predicate;
            }
        }
        if ($method === 'last' || $method === 'lastOrDefault') {
            if ($plan->orderings === []) {
                throw new \LogicException('SQL last()/lastOrDefault() requires an explicit orderBy().');
            }
            if ($plan->limit !== null || $plan->offset !== null) {
                $wrapped = new QueryPlan('__linq_result');
                $wrapped->sourcePlan = $plan;
                if ($plan->selection instanceof FieldExpression || $plan->selection instanceof FunctionExpression
                    || $plan->selection instanceof AggregateExpression) {
                    $wrapped->selection = new FieldExpression('__linq_value');
                }
                foreach ($plan->orderings as $ordering) {
                    $wrapped->orderings[] = [
                        'expression' => $this->projectedOrdering($ordering['expression'], $plan->selection),
                        'descending' => $ordering['descending'],
                    ];
                }
                $plan = $wrapped;
            }
            foreach ($plan->orderings as &$ordering) $ordering['descending'] = !$ordering['descending'];
            unset($ordering);
        }
        if ($plan->terminalPredicate !== null) $this->validateValue($plan->terminalPredicate, false);
        $plan->terminal = $method;
        $plan->defaultValue = $expression->arguments['default'] ?? null;
        return $plan;
    }
    private function projectedOrdering(ValueExpression $value, ?ValueExpression $selection): ValueExpression
    {
        if ($selection === null) return $value;
        if (!$selection instanceof ProjectionExpression && $selection == $value) return new FieldExpression('__linq_value');
        if ($selection instanceof ProjectionExpression) {
            foreach ($selection->members as $name => $member) {
                if ($member == $value) return new FieldExpression($name);
            }
        }
        if ($value instanceof ConstantExpression) return $value;
        throw new \LogicException('Include ordering keys in the named projection before a terminal predicate.');
    }
    private function validatePlan(QueryPlan $plan): void
    {
        if ($plan->groupKey !== null && $plan->selection === null) {
            throw new \LogicException('SQL grouping requires a key/aggregate projection; full groups are not supported.');
        }
        foreach ($plan->predicates as $predicate) $this->validateValue($predicate, false);
        if ($plan->collectionPredicate !== null) $this->validateValue($plan->collectionPredicate, false);
        foreach (array_merge($plan->havingPredicates,
            $plan->selection === null ? [] : [$plan->selection],
            array_column($plan->orderings, 'expression'),
            $plan->joinCondition === null ? [] : [$plan->joinCondition]) as $value) {
            $this->validateValue($value, $plan->groupKey !== null);
        }
        if ($plan->setLeft !== null) $this->validatePlan($plan->setLeft);
        if ($plan->setRight !== null) $this->validatePlan($plan->setRight);
        if ($plan->sourcePlan !== null) $this->validatePlan($plan->sourcePlan);
        if ($plan->innerPlan !== null) $this->validatePlan($plan->innerPlan);
    }
    private function validateValue(ValueExpression $value, bool $grouped): void
    {
        if ($value instanceof GroupKeyExpression) throw new \LogicException('Group keys require groupBy().');
        if ($value instanceof AggregateExpression) {
            if (!$grouped) throw new \LogicException('Aggregates require groupBy().');
            if ($value->operand !== null) $this->validateValue($value->operand, false);
        } elseif ($value instanceof BinaryExpression || $value instanceof LogicalExpression) {
            $this->validateValue($value->left, $grouped);
            $this->validateValue($value->right, $grouped);
        } elseif ($value instanceof ProjectionExpression || $value instanceof FunctionExpression) {
            foreach ($value instanceof ProjectionExpression ? $value->members : $value->arguments as $child) {
                $this->validateValue($child, $grouped);
            }
        } elseif ($value instanceof NotExpression) $this->validateValue($value->operand, $grouped);
    }
    private function resolveGrouped(ValueExpression $value, ?ValueExpression $key, bool $operand = false): ValueExpression
    {
        if ($value instanceof GroupKeyExpression) {
            if ($operand || $key === null) throw new \LogicException('Group keys are not valid here.');
            if ($value->member === null) return $key;
            if (!$key instanceof ProjectionExpression || !isset($key->members[$value->member])) {
                throw new \LogicException('Unknown composite group key member.');
            }
            return $key->members[$value->member];
        }
        if ($value instanceof AggregateExpression) {
            if ($operand || $key === null) throw new \LogicException('Nested aggregates are not supported.');
            return new AggregateExpression($value->function, $value->operand === null ? null
                : $this->resolveGrouped($value->operand, null, true));
        }
        if ($value instanceof FieldExpression) {
            if (!$operand) throw new \LogicException('Grouped fields must be referenced through Expr::key() or an aggregate.');
            return $value;
        }
        if ($value instanceof ConstantExpression) return $value;
        if ($value instanceof ProjectionExpression) {
            return new ProjectionExpression(array_map(fn ($v) => $this->resolveGrouped($v, $key, $operand), $value->members));
        }
        if ($value instanceof BinaryExpression || $value instanceof LogicalExpression) {
            $class = $value::class;
            return new $class($this->resolveGrouped($value->left, $key, $operand), $value->operator,
                $this->resolveGrouped($value->right, $key, $operand));
        }
        if ($value instanceof FunctionExpression) {
            return new FunctionExpression($value->definition,
                array_map(fn ($v) => $this->resolveGrouped($v, $key, $operand), $value->arguments));
        }
        if ($value instanceof NotExpression) return new NotExpression($this->resolveGrouped($value->operand, $key, $operand));
        throw new \LogicException('Unsupported grouped expression.');
    }
    private function joinCondition(ValueExpression $outer, ValueExpression $inner): ValueExpression
    {
        if ($outer instanceof ProjectionExpression || $inner instanceof ProjectionExpression) {
            if (!$outer instanceof ProjectionExpression || !$inner instanceof ProjectionExpression
                || array_keys($outer->members) !== array_keys($inner->members)) {
                throw new \InvalidArgumentException('Composite join keys must have the same member names and order.');
            }
            $condition = null;
            foreach ($outer->members as $name => $member) {
                $part = $this->joinCondition($member, $inner->members[$name]);
                $condition = $condition === null ? $part : new LogicalExpression($condition, 'and', $part);
            }
            return $condition;
        }
        return new BinaryExpression($this->qualify($outer, 'outer'), '=', $this->qualify($inner, 'inner'));
    }

    private function qualify(ValueExpression $expression, string $alias): ValueExpression
    {
        return match (true) {
            $expression instanceof FieldExpression => new FieldExpression($alias.'.'.$expression->path),
            $expression instanceof ConstantExpression => $expression,
            $expression instanceof FunctionExpression => new FunctionExpression(
                $expression->definition,
                array_map(fn ($argument) => $this->qualify($argument, $alias), $expression->arguments),
            ),
            $expression instanceof BinaryExpression => new BinaryExpression(
                $this->qualify($expression->left, $alias),
                $expression->operator,
                $this->qualify($expression->right, $alias),
            ),
            $expression instanceof LogicalExpression => new LogicalExpression(
                $this->qualify($expression->left, $alias),
                $expression->operator,
                $this->qualify($expression->right, $alias),
            ),
            default => throw new \LogicException('Unsupported join key expression.'),
        };
    }
}
