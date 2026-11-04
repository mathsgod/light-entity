<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Linq\Expression\ValueExpression;

/** Context-local query configuration; built once, with deferred runtime predicates. */
final class ModelBuilder
{
    private array $filters = [];
    private bool $sealed = false;

    public function entity(string $class): EntityTypeBuilder
    {
        if (!class_exists($class)) { throw new \InvalidArgumentException('Entity class does not exist: '.$class); }
        return new EntityTypeBuilder($this, $class);
    }

    /** @internal */
    public function setQueryFilter(string $class, string $name, ValueExpression|array|callable $filter): void
    {
        if ($this->sealed) { throw new \LogicException('The model has already been configured.'); }
        if (trim($name) === '') { throw new \InvalidArgumentException('Query filters require a non-empty name.'); }
        $this->filters[$class][$name] = is_callable($filter) ? \Closure::fromCallable($filter) : $filter;
    }

    /** @internal */
    public function seal(): void { $this->sealed = true; }
    /** @internal */
    public function filters(string $class): array { return $this->filters[$class] ?? []; }
    /** @internal */
    public function hasFilters(): bool { return $this->filters !== []; }
}
