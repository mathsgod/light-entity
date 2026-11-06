<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Sql\NotExpression;
use Light\Linq\Expr;
use Light\Linq\Expressions\ValueExpression;

/** Converts GraphQL-compatible filter arrays to parameterized query expressions. */
final class WhereFilter
{
    public static function parse(array $filter): ValueExpression
    {
        $parts = [];
        foreach ($filter as $field => $condition) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException('Filter keys must be field names or logical operators.');
            }
            if (in_array($field, ['_and', '_or'], true)) {
                if (!is_array($condition) || !array_is_list($condition)) {
                    throw new \InvalidArgumentException("{$field} requires a list of filters.");
                }
                $children = [];
                foreach ($condition as $child) {
                    if (!is_array($child)) {
                        throw new \InvalidArgumentException("{$field} entries must be filter arrays.");
                    }
                    $children[] = self::parse($child);
                }
                $parts[] = self::combine($children, $field === '_and');
            } elseif ($field === '_not') {
                if (!is_array($condition)) {
                    throw new \InvalidArgumentException('_not requires a filter array.');
                }
                $parts[] = new NotExpression(self::parse($condition));
            } else {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $field) && !preg_match('/^_[A-Za-z0-9_]+$/D', $field)) {
                    throw new \InvalidArgumentException("Invalid filter field: {$field}");
                }
                $operators = is_array($condition) ? $condition : ['eq' => $condition];
                if ($operators === [] || array_is_list($operators)) {
                    throw new \InvalidArgumentException("{$field} requires a scalar or an operator map.");
                }
                foreach ($operators as $operator => $value) {
                    $left = Expr::field($field);
                    if ($operator === 'in') {
                        if (!is_array($value) || !array_is_list($value)) {
                            throw new \InvalidArgumentException('in requires a list of scalar values.');
                        }
                        $items = [];
                        foreach ($value as $item) {
                            self::validateValue($item);
                            $items[] = Expr::eq($left, $item);
                        }
                        $parts[] = self::combine($items, false);
                        continue;
                    }
                    if (!in_array($operator, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'], true)) {
                        throw new \InvalidArgumentException('Unknown filter operator: '.(string) $operator);
                    }
                    self::validateValue($value);
                    if ($value === null && !in_array($operator, ['eq', 'neq'], true)) {
                        throw new \InvalidArgumentException('Only eq and neq accept null.');
                    }
                    $parts[] = Expr::$operator($left, $value);
                }
            }
        }
        return self::combine($parts, true);
    }

    private static function validateValue(mixed $value): void
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Filter operands must be scalar values or null.');
        }
    }

    private static function combine(array $parts, bool $and): ValueExpression
    {
        if ($parts === []) {
            return Expr::eq(Expr::value(1), $and ? 1 : 0);
        }
        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result = $and ? Expr::both($result, $part) : Expr::either($result, $part);
        }
        return $result;
    }
}
