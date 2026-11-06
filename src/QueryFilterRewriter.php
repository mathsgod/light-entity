<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Sql\EntityQuerySourceExpression;
use Light\Linq\Expressions\{Expression, MethodCallExpression};

/** Rewrites sources in composed queries, including join/set-operation operands. @internal */
final class QueryFilterRewriter
{
    public static function rewrite(Expression $expression, callable $source): Expression
    {
        if ($expression instanceof EntityQuerySourceExpression) { return $source($expression); }
        if ($expression instanceof \Light\Linq\Expressions\ContainsExpression) {
            return new \Light\Linq\Expressions\ContainsExpression(
                self::rewrite($expression->item, $source), self::rewrite($expression->source, $source),
            );
        }
        if ($expression instanceof \Light\Linq\Expressions\AnyExpression) {
            return new \Light\Linq\Expressions\AnyExpression(self::rewrite($expression->source, $source));
        }
        if ($expression instanceof \Light\Entity\Sql\ExistsExpression) {
            return new \Light\Entity\Sql\ExistsExpression(self::rewrite($expression->subquery, $source));
        }
        if ($expression instanceof \Light\Entity\Sql\InExpression) {
            return new \Light\Entity\Sql\InExpression(
                self::rewrite($expression->item, $source), self::rewrite($expression->subquery, $source), $expression->negated,
            );
        }
        if ($expression instanceof \Light\Linq\Expressions\BinaryExpression || $expression instanceof \Light\Linq\Expressions\LogicalExpression) {
            $class = $expression::class;
            return new $class(self::rewrite($expression->left, $source), $expression->operator, self::rewrite($expression->right, $source));
        }
        if ($expression instanceof \Light\Entity\Sql\NotExpression || $expression instanceof \Light\Linq\Expressions\NotExpression) {
            $class = $expression::class;
            return new $class(self::rewrite($expression->operand, $source));
        }
        if ($expression instanceof \Light\Linq\Expressions\ProjectionExpression) {
            return new \Light\Linq\Expressions\ProjectionExpression(array_map(fn ($v) => self::rewrite($v, $source), $expression->members));
        }
        if ($expression instanceof \Light\Entity\Sql\FunctionExpression) {
            return new \Light\Entity\Sql\FunctionExpression($expression->definition, array_map(fn ($v) => self::rewrite($v, $source), $expression->arguments));
        }
        if ($expression instanceof \Light\Linq\Expressions\AggregateExpression) {
            return new \Light\Linq\Expressions\AggregateExpression($expression->function,
                $expression->operand === null ? null : self::rewrite($expression->operand, $source));
        }
        if (!$expression instanceof MethodCallExpression) { return $expression; }
        $rewrittenSource = self::rewrite($expression->source, $source);
        $arguments = $expression->arguments;
        foreach ($arguments as $key => $argument) {
            if ($argument instanceof Expression) {
                $arguments[$key] = self::rewrite($argument, $source);
            }
        }
        return $rewrittenSource === $expression->source && $arguments === $expression->arguments
            ? $expression : new MethodCallExpression($rewrittenSource, $expression->method, $arguments);
    }

    public static function ignore(Expression $expression, ?array $names): Expression
    {
        if ($names !== null) {
            foreach ($names as $name) {
                if (!is_string($name) || trim($name) === '') { throw new \InvalidArgumentException('Ignored filter names must be non-empty strings.'); }
            }
        }
        return self::rewrite($expression, static fn (EntityQuerySourceExpression $source) => new EntityQuerySourceExpression(
            $source->mapping, $source->source,
            $names === null || $source->ignoredFilters === null ? null : array_values(array_unique([...$source->ignoredFilters, ...$names])),
        ));
    }

    public static function ignored(Expression $expression): ?array
    {
        while ($expression instanceof MethodCallExpression) { $expression = $expression->source; }
        return $expression instanceof EntityQuerySourceExpression ? $expression->ignoredFilters : [];
    }
}
