<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\IQueryable;
use Light\Linq\Expressions\{Expression, ValueExpression};

/** SQL membership in a single-column subquery; the query is never materialized. */
final readonly class InExpression implements ValueExpression
{
    public Expression $subquery;

    public function __construct(
        public ValueExpression $item,
        IQueryable|Expression $subquery,
        public bool $negated = false,
    ) {
        $this->subquery = $subquery instanceof IQueryable ? $subquery->expression() : $subquery;
    }
}
