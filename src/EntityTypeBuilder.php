<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Linq\Expressions\ValueExpression;

/** Fluent configuration for one entity type. */
final readonly class EntityTypeBuilder
{
    public function __construct(private ModelBuilder $model, private string $class) {}

    /** Callbacks receive the executing DbContext and return a predicate or condition map. */
    public function hasQueryFilter(string $name, ValueExpression|array|callable $filter): self
    {
        $this->model->setQueryFilter($this->class, $name, $filter);
        return $this;
    }
}
