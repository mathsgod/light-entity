<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Linq\{ClosureTranslator, IQueryable, IQueryProvider};
use Light\Linq\Expressions\{Expression, ValueExpression, AnyExpression, BinaryExpression, LogicalExpression, MethodCallExpression, NotExpression};
use Light\Entity\Sql\EntityQuerySourceExpression;

/** Applies model converters only to predicates introduced by this closure. @internal */
final class ClosureQueryTranslator
{
    public static function translate(\Closure $closure, EntityMapping $mapping, IQueryProvider $provider): ValueExpression
    {
        $captured = new \SplObjectStorage();
        foreach ((new \ReflectionFunction($closure))->getStaticVariables() as $value) {
            if ($value instanceof IQueryable) { $captured->offsetSet($value->expression()); }
        }
        return self::convert(ClosureTranslator::translate($closure, $provider), $mapping, $captured);
    }

    private static function convert(ValueExpression $value, ?EntityMapping $mapping, \SplObjectStorage $captured): ValueExpression
    {
        if ($value instanceof AnyExpression) {
            $query = $value->source;
            if ($query instanceof MethodCallExpression && $query->method === 'where' && !$captured->offsetExists($query)) {
                $innerMapping = self::mapping($query->source);
                $arguments = $query->arguments;
                $arguments['predicate'] = self::convert($arguments['predicate'], $innerMapping, $captured);
                return new AnyExpression(new MethodCallExpression($query->source, 'where', $arguments));
            }
            return $value;
        }
        if ($value instanceof LogicalExpression || $value instanceof BinaryExpression) {
            $class = $value::class;
            $result = new $class(self::convert($value->left, $mapping, $captured), $value->operator,
                self::convert($value->right, $mapping, $captured));
            return $value instanceof BinaryExpression && $mapping !== null ? $mapping->convertPredicate($result) : $result;
        }
        if ($value instanceof NotExpression) { return new NotExpression(self::convert($value->operand, $mapping, $captured)); }
        return $value;
    }

    private static function mapping(Expression $query): ?EntityMapping
    {
        while ($query instanceof MethodCallExpression) {
            if (!in_array($query->method, ['where', 'orderBy', 'thenBy', 'skip', 'take', 'distinct'], true)) { return null; }
            $query = $query->source;
        }
        return $query instanceof EntityQuerySourceExpression ? $query->mapping : null;
    }
}
