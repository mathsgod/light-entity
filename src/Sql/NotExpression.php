<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\ValueExpression;

final readonly class NotExpression implements ValueExpression
{
    public function __construct(public ValueExpression $operand) {}
}
