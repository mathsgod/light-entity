<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

use PhpLinq\Expression\ValueExpression;

/** SQL-only scalar call; the PHP runtime never evaluates the database function. */
final readonly class FunctionExpression implements ValueExpression
{
    public array $arguments;

    /** @param list<ValueExpression> $arguments */
    public function __construct(public SqlFunctionDefinition $definition, array $arguments)
    {
        foreach ($arguments as $argument) {
            if (!$argument instanceof ValueExpression) {
                throw new \InvalidArgumentException('Function arguments must be value expressions.');
            }
        }
        if ($definition->parameterCount !== null && count($arguments) !== $definition->parameterCount) {
            throw new \InvalidArgumentException("Function {$definition->name} expects {$definition->parameterCount} argument(s); received ".count($arguments).'.');
        }
        $this->arguments = array_values($arguments);
    }
}
