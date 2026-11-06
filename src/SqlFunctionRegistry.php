<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Sql\FunctionExpression;
use Light\Entity\Sql\SqlFunctionDefinition;
use Light\Linq\Expressions\ConstantExpression;
use Light\Linq\Expressions\ValueExpression;

/** Context-scoped scalar function mappings and expression construction. */
final class SqlFunctionRegistry
{
    /** @var array<string, SqlFunctionDefinition> */
    private array $functions = [];

    public function register(string $name, ?string $schema = null, ?int $parameterCount = null): void
    {
        $definition = new SqlFunctionDefinition($name, $schema, $parameterCount);
        $key = strtolower($name);
        if (isset($this->functions[$key])) { throw new \LogicException("Function already registered: {$name}"); }
        $this->functions[$key] = $definition;
    }

    public function call(string $name, ValueExpression|int|float|string|bool|null ...$arguments): FunctionExpression
    {
        $definition = $this->functions[strtolower($name)]
            ?? new SqlFunctionDefinition($name);
        return new FunctionExpression($definition, array_map(
            fn ($argument) => $argument instanceof ValueExpression ? $argument : new ConstantExpression($argument),
            array_values($arguments),
        ));
    }
}
