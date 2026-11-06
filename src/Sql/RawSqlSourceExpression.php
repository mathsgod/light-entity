<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\Expression;

/** A trusted, composable SELECT source with bound values; never an entity table mapping. */
final readonly class RawSqlSourceExpression implements Expression
{
    public function __construct(public string $sql, public array $parameters = [])
    {
        if (trim($sql) === '') { throw new \InvalidArgumentException('Raw SQL cannot be empty.'); }
        foreach ($parameters as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException('Raw SQL parameters must be scalar or null.');
            }
        }
    }
}
