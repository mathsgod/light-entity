<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Linq\Expressions\ValueExpression;

/** Context-local query configuration; built once, with deferred runtime predicates. */
final class ModelBuilder
{
    private array $filters = [];
    private bool $sealed = false;
    private array $discriminators = [];
    private array $complexProperties = [];

    public function entity(string $class): EntityTypeBuilder
    {
        if (!class_exists($class)) { throw new \InvalidArgumentException('Entity class does not exist: '.$class); }
        return new EntityTypeBuilder($this, $class);
    }

    /** @internal */
    public function setComplexProperty(string $owner, string $name, ?string $class, array $columns): void
    {
        if ($this->sealed) throw new \LogicException('The model has already been configured.');
        if ($class === null) {
            $reflection = new \ReflectionClass($owner);
            $type = $reflection->hasProperty($name) ? $reflection->getProperty($name)->getType() : null;
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \InvalidArgumentException('Specify the complex type or declare a typed object property.');
            }
            $class = $type->getName();
        }
        $mapping = new ComplexPropertyMapping($name, $class, $columns);
        $mapping->validateOwner(new \ReflectionClass($owner));
        $this->complexProperties[$owner][$name] = $mapping;
    }

    /** @internal */
    public function complexProperties(): array { return $this->complexProperties; }

    /** @internal */
    public function setDiscriminator(string $root, string $column): DiscriminatorBuilder
    {
        if ($this->sealed) throw new \LogicException('The model has already been configured.');
        if ($column === '' || str_contains($column, '.')) throw new \InvalidArgumentException('Discriminator requires an unqualified column name.');
        if (!is_subclass_of($root, Entity::class)) throw new \InvalidArgumentException('TPH entities must extend Entity.');
        $this->discriminators[$root] = ['column' => $column, 'values' => $this->discriminators[$root]['values'] ?? []];
        return new DiscriminatorBuilder($this, $root);
    }

    /** @internal */
    public function setDiscriminatorValue(string $root, string $class, string $value): void
    {
        if ($this->sealed) throw new \LogicException('The model has already been configured.');
        if (!is_a($class, $root, true) || $value === '') throw new \InvalidArgumentException('Invalid discriminator type or value.');
        $this->discriminators[$root]['values'][$class] = $value;
    }

    /** @internal */
    public function discriminators(): array { return $this->discriminators; }

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
    public function filters(string $class): array { $filters = [];
        foreach (array_reverse([$class, ...array_values(class_parents($class))]) as $type) {
            $filters = array_replace($filters, $this->filters[$type] ?? []);
        }
        return $filters; }
    /** @internal */
    public function hasFilters(): bool { return $this->filters !== []; }
}
