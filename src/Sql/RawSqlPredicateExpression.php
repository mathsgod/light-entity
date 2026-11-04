<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expression\ValueExpression;

/** A trusted SQL condition; its parameters are provider values, not SQL fragments. */
final readonly class RawSqlPredicateExpression implements ValueExpression
{
    public function __construct(public string $sql, public array $parameters = [])
    {
        if (trim($sql) === '' || str_contains($sql, "\0")) {
            throw new \InvalidArgumentException('Raw SQL conditions must be non-empty and contain no NUL bytes.');
        }
        foreach ($parameters as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException('Raw SQL parameters must be scalar or null.');
            }
        }
    }
}
