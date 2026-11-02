<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expression\ValueExpression;

final class QueryPlan
{
    /** @var list<ValueExpression> */
    public array $predicates = [];

    /** @var list<array{expression: ValueExpression, descending: bool}> */
    public array $orderings = [];

    public ?ValueExpression $groupKey = null;
    /** @var list<ValueExpression> */
    public array $havingPredicates = [];
    public bool $distinct = false;
    public ?self $sourcePlan = null;
    public ?RawSqlSourceExpression $rawSource = null;
    public ?self $innerPlan = null;
    public ?ValueExpression $joinCondition = null;
    public ?ValueExpression $collectionPredicate = null;
    public string $joinType = 'INNER';

    public ?ValueExpression $selection = null;
    public ?int $offset = null;
    public ?int $limit = null;
    public string $terminal = 'sequence';
    public ?ValueExpression $terminalPredicate = null;
    public mixed $defaultValue = null;
    public ?ValueExpression $aggregateSelector = null;

    public function __construct(public readonly string $table)
    {
    }
}
