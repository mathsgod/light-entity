<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Sql\EntityQuerySourceExpression;
use Light\Linq\Expression\{Expression, MethodCallExpression, ValueExpression};

/** Rewrites sources in composed queries, including join/set-operation operands. @internal */
final class QueryFilterRewriter
{
    public static function rewrite(Expression $expression, callable $source): Expression
    {
        if ($expression instanceof EntityQuerySourceExpression) { return $source($expression); }
        if (!$expression instanceof MethodCallExpression) { return $expression; }
        $rewrittenSource = self::rewrite($expression->source, $source);
        $arguments = $expression->arguments;
        foreach ($arguments as $key => $argument) {
            if ($argument instanceof Expression && !$argument instanceof ValueExpression) {
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
