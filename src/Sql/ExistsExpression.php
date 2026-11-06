<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\{Expression, ValueExpression};

/** SQL EXISTS, produced from a provider-independent AnyExpression. */
final readonly class ExistsExpression implements ValueExpression
{
    public function __construct(public Expression $subquery) {}
}
