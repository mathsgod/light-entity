<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

use PhpLinq\Expression\ValueExpression;

final readonly class NotExpression implements ValueExpression
{
    public function __construct(public ValueExpression $operand) {}
}
