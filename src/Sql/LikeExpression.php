<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\ValueExpression;

/** SQL LIKE, with an optional explicit escape character. */
final readonly class LikeExpression implements ValueExpression
{
    public function __construct(
        public ValueExpression $match,
        public ValueExpression $pattern,
        public ?string $escape = null,
    ) {
        if ($escape !== null && strlen($escape) !== 1) {
            throw new \InvalidArgumentException('LIKE escape must be one byte.');
        }
    }
}
