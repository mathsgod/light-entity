<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

use PhpLinq\Expression\Expression;
use PhpLinq\Expression\BinaryExpression;
use PhpLinq\Expression\ConstantExpression;
use PhpLinq\Expression\FieldExpression;
use PhpLinq\Expression\LogicalExpression;
use PhpLinq\Expression\ProjectionExpression;
use PhpLinq\Expression\ValueExpression;
use PhpLinq\Expression\MethodCallExpression;
use PhpLinq\Expression\SourceExpression;

final readonly class SqlQueryCompiler
{
    public function __construct(private SqlDialect $dialect)
    {
    }

    public function compile(Expression $expression): CompiledQuery
    {
        return $this->dialect->compile($this->plan($expression));
    }

    private function plan(Expression $expression): QueryPlan
    {
        $operations = [];
        while ($expression instanceof MethodCallExpression) {
            array_unshift($operations, $expression);
            $expression = $expression->source;
        }
        if (!$expression instanceof SourceExpression) {
            throw new \LogicException('A SQL query must start with a named source.');
        }

        $plan = new QueryPlan($expression->name);
        $paginationStarted = false;
        $joinedProjection = false;
        foreach ($operations as $operation) {
            if (in_array($operation->method, ['join', 'leftJoin'], true)) {
                $inner = $this->plan($operation->arguments['inner']);
                if ($plan->selection instanceof FieldExpression || $inner->selection instanceof FieldExpression) {
                    throw new \LogicException('Join sources must return rows; use a named projection instead of a scalar selection.');
                }
                $joined = new QueryPlan('__linq_outer');
                $joined->sourcePlan = $plan;
                $joined->innerPlan = $inner;
                $joined->joinType = $operation->method === 'leftJoin' ? 'LEFT' : 'INNER';
                $joined->joinCondition = $this->joinCondition(
                    $operation->arguments['outerKey'], $operation->arguments['innerKey'],
                );
                $joined->selection = $operation->arguments['resultSelector'];
                $plan = $joined;
                $paginationStarted = false;
                $joinedProjection = true;
                continue;
            }
            // Subsequent operators address the projected JOIN result, not its sources.
            if ($joinedProjection && in_array($operation->method, ['where', 'orderBy', 'select'], true)) {
                if ($plan->selection instanceof FieldExpression) {
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

            match ($operation->method) {
                'where' => $plan->predicates[] = $operation->arguments['predicate'],
                'select' => $plan->selection = $operation->arguments['selector'],
                'orderBy' => $plan->orderings[] = [
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

        return $plan;
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
