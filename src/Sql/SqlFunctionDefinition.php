<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

/** Immutable mapping to a database scalar function; registration does not create it. */
final readonly class SqlFunctionDefinition
{
    public function __construct(
        public string $name,
        public ?string $schema = null,
        public ?int $parameterCount = null,
    ) {
        foreach ([$name, ...($schema === null ? [] : [$schema])] as $identifier) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)) {
                throw new \InvalidArgumentException('Function names and schemas must be simple SQL identifiers.');
            }
        }
        if ($parameterCount !== null && $parameterCount < 0) {
            throw new \InvalidArgumentException('Function parameter count must be non-negative.');
        }
    }
}
