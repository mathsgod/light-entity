<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Sql\EntityQuerySourceExpression;
use Light\Linq\Expressions\{Expression, ValueExpression, MethodCallExpression, FieldExpression, OuterFieldExpression,
    AnyExpression, ContainsExpression, BinaryExpression, LogicalExpression, ProjectionExpression, AggregateExpression,
    ScalarFunctionExpression, CoalesceExpression, ConditionalExpression};

/** Binds complex member paths while each query source still retains its entity metadata. @internal */
final class ComplexQueryRewriter
{
    public static function rewrite(Expression $expression): Expression
    {
        return self::query($expression)[0];
    }

    public static function column(string $path, array $paths): string
    {
        if (array_key_exists($path, $paths)) {
            return $paths[$path] ?? throw new \LogicException('Select or compare individual complex members; whole complex objects are not SQL values: '.$path);
        }
        foreach ($paths as $root => $column) {
            if ($column === null && str_starts_with($path, $root.'.')) {
                throw new \LogicException('Unknown or nested complex member: '.$path);
            }
        }
        return $path;
    }

    /** @return array{Expression, array<string, string|null>} */
    private static function query(Expression $expression, array $outerScopes = []): array
    {
        if ($expression instanceof EntityQuerySourceExpression) return [$expression, $expression->mapping->complexPaths()];
        if (!$expression instanceof MethodCallExpression) return [$expression, []];
        [$source, $paths] = self::query($expression->source, $outerScopes);
        $arguments = $expression->arguments;
        $innerPaths = [];
        foreach (['inner', 'second'] as $name) {
            if (isset($arguments[$name]) && $arguments[$name] instanceof Expression && !$arguments[$name] instanceof ValueExpression) {
                [$arguments[$name], $innerPaths] = self::query($arguments[$name], $outerScopes);
            }
        }
        $joined = [];
        foreach (['outer' => $paths, 'inner' => $innerPaths] as $prefix => $members) {
            foreach ($members as $name => $column) $joined[$prefix.'.'.$name] = $column === null ? null : $prefix.'.'.$column;
        }
        $isJoin = in_array($expression->method, ['join', 'leftJoin', 'rightJoin', 'groupJoin', 'selectMany', 'zip'], true);
        foreach ($arguments as $name => $value) {
            if (!$value instanceof ValueExpression) continue;
            $scope = $name === 'innerKey' ? $innerPaths
                : ($isJoin && in_array($name, ['resultSelector', 'predicate'], true) ? $joined : $paths);
            $arguments[$name] = self::value($value, $scope, $outerScopes);
        }
        $result = $source === $expression->source && $arguments === $expression->arguments ? $expression
            : new MethodCallExpression($source, $expression->method, $arguments);
        return [$result, $expression->method === 'select' || $isJoin ? [] : $paths];
    }

    /** Rewrite a scalar expression using logical paths in its own source and enclosing scopes. */
    public static function value(ValueExpression $value, array $paths, array $outerScopes = []): ValueExpression
    {
        if ($value instanceof FieldExpression) {
            $column = self::column($value->path, $paths);
            return $column === $value->path ? $value : new FieldExpression($column);
        }
        if ($value instanceof OuterFieldExpression) {
            $column = self::column($value->path, $outerScopes[$value->depth - 1] ?? []);
            return $column === $value->path ? $value : new OuterFieldExpression($column, $value->depth);
        }
        if ($value instanceof AnyExpression) return new AnyExpression(self::query($value->source, [$paths, ...$outerScopes])[0]);
        if ($value instanceof ContainsExpression) return new ContainsExpression(
            self::value($value->item, $paths, $outerScopes), self::query($value->source, [$paths, ...$outerScopes])[0],
        );
        if ($value instanceof Sql\ExistsExpression) return new Sql\ExistsExpression(self::query($value->subquery, [$paths, ...$outerScopes])[0]);
        if ($value instanceof Sql\InExpression) return new Sql\InExpression(self::value($value->item, $paths, $outerScopes),
            self::query($value->subquery, [$paths, ...$outerScopes])[0], $value->negated);
        if ($value instanceof BinaryExpression || $value instanceof LogicalExpression) {
            $class = $value::class;
            return new $class(self::value($value->left, $paths, $outerScopes), $value->operator, self::value($value->right, $paths, $outerScopes));
        }
        if ($value instanceof \Light\Linq\Expressions\NotExpression || $value instanceof Sql\NotExpression) {
            $class = $value::class;
            return new $class(self::value($value->operand, $paths, $outerScopes));
        }
        if ($value instanceof ProjectionExpression) return new ProjectionExpression(array_map(fn ($v) => self::value($v, $paths, $outerScopes), $value->members));
        if ($value instanceof AggregateExpression) return new AggregateExpression($value->function,
            $value->operand === null ? null : self::value($value->operand, $paths, $outerScopes));
        if ($value instanceof ScalarFunctionExpression) return new ScalarFunctionExpression($value->function,
            array_map(fn ($v) => self::value($v, $paths, $outerScopes), $value->arguments));
        if ($value instanceof CoalesceExpression) return new CoalesceExpression(self::value($value->left, $paths, $outerScopes), self::value($value->right, $paths, $outerScopes));
        if ($value instanceof ConditionalExpression) return new ConditionalExpression(self::value($value->test, $paths, $outerScopes),
            self::value($value->ifTrue, $paths, $outerScopes), self::value($value->ifFalse, $paths, $outerScopes));
        if ($value instanceof Sql\LikeExpression) return new Sql\LikeExpression(self::value($value->match, $paths, $outerScopes), self::value($value->pattern, $paths, $outerScopes), $value->escape);
        if ($value instanceof Sql\StringPatternExpression) return new Sql\StringPatternExpression(self::value($value->needle, $paths, $outerScopes), $value->operation);
        if ($value instanceof \Light\Linq\Expressions\StringMatchExpression) return new \Light\Linq\Expressions\StringMatchExpression(
            self::value($value->subject, $paths, $outerScopes), $value->operation, self::value($value->needle, $paths, $outerScopes));
        if ($value instanceof Sql\FunctionExpression) return new Sql\FunctionExpression($value->definition,
            array_map(fn ($v) => self::value($v, $paths, $outerScopes), $value->arguments));
        return $value;
    }
}
