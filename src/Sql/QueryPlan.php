<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

use PhpLinq\Expression\ValueExpression;

final class QueryPlan
{
    /** @var list<ValueExpression> */
    public array $predicates = [];

    /** @var list<array{expression: ValueExpression, descending: bool}> */
    public array $orderings = [];

    public ?self $sourcePlan = null;
    public ?self $innerPlan = null;
    public ?ValueExpression $joinCondition = null;
    public string $joinType = 'INNER';

    public ?ValueExpression $selection = null;
    public ?int $offset = null;
    public ?int $limit = null;
    public string $terminal = 'sequence';

    public function __construct(public readonly string $table)
    {
    }
}
