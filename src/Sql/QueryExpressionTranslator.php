<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\{CoalesceExpression, ConditionalExpression, ScalarFunctionExpression};

use Light\Linq\Expressions\{Expression, AnyExpression, ContainsExpression, BinaryExpression, LogicalExpression, MethodCallExpression, ProjectionExpression, AggregateExpression};

/** Lowers LINQ operations to SQL nodes before relational planning. @internal */
final class QueryExpressionTranslator
{
    public static function translate(Expression $expression): Expression
    {
        if ($expression instanceof \Light\Linq\Expressions\StringMatchExpression) {
            return new LikeExpression(self::translate($expression->subject),
                new StringPatternExpression(self::translate($expression->needle), $expression->operation), '!');
        }
        if ($expression instanceof LikeExpression) {
            return new LikeExpression(self::translate($expression->match), self::translate($expression->pattern), $expression->escape);
        }
        if ($expression instanceof StringPatternExpression) {
            return new StringPatternExpression(self::translate($expression->needle), $expression->operation);
        }
        if ($expression instanceof ScalarFunctionExpression) {
            return new ScalarFunctionExpression($expression->function, array_map(self::translate(...), $expression->arguments));
        }
        if ($expression instanceof CoalesceExpression) {
            return new CoalesceExpression(self::translate($expression->left), self::translate($expression->right));
        }
        if ($expression instanceof ConditionalExpression) {
            return new ConditionalExpression(self::translate($expression->test),
                self::translate($expression->ifTrue), self::translate($expression->ifFalse));
        }
        if ($expression instanceof AnyExpression) { return new ExistsExpression(self::translate($expression->source)); }
        if ($expression instanceof ContainsExpression) {
            return new InExpression(self::translate($expression->item), self::translate($expression->source));
        }
        if ($expression instanceof ExistsExpression) { return new ExistsExpression(self::translate($expression->subquery)); }
        if ($expression instanceof InExpression) {
            return new InExpression(self::translate($expression->item), self::translate($expression->subquery), $expression->negated);
        }
        if ($expression instanceof \Light\Linq\Expressions\NotExpression || $expression instanceof NotExpression) {
            return new NotExpression(self::translate($expression->operand));
        }
        if ($expression instanceof BinaryExpression || $expression instanceof LogicalExpression) {
            $class = $expression::class;
            return new $class(self::translate($expression->left), $expression->operator, self::translate($expression->right));
        }
        if ($expression instanceof MethodCallExpression) {
            $arguments = $expression->arguments;
            foreach ($arguments as $name => $value) {
                if ($value instanceof Expression) { $arguments[$name] = self::translate($value); }
            }
            return new MethodCallExpression(self::translate($expression->source), $expression->method, $arguments);
        }
        if ($expression instanceof ProjectionExpression) {
            return new ProjectionExpression(array_map(self::translate(...), $expression->members));
        }
        if ($expression instanceof FunctionExpression) {
            return new FunctionExpression($expression->definition, array_map(self::translate(...), $expression->arguments));
        }
        if ($expression instanceof AggregateExpression && $expression->operand !== null) {
            return new AggregateExpression($expression->function, self::translate($expression->operand));
        }
        return $expression;
    }
}
