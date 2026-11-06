<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Entity\EntityMapping;
use Light\Linq\Expressions\Expression;

/** Retains entity identity until filters are expanded immediately before SQL compilation. @internal */
final readonly class EntityQuerySourceExpression implements Expression
{
    public function __construct(
        public EntityMapping $mapping,
        public Expression $source,
        public ?array $ignoredFilters = [],
        public ?\Closure $filterResolver = null,
    ) {}
}
