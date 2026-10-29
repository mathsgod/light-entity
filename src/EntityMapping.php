<?php

declare(strict_types=1);

namespace PhpEntity;

/** Runtime metadata. Usually discovered from the database schema. */
final readonly class EntityMapping
{
    /** @param class-string $class @param non-empty-list<string> $properties */
    public function __construct(
        public string $class,
        public string $table,
        public array $properties,
        public string $key = 'id',
        public bool $generatedKey = true,
        public array $columns = [],
    ) {
        if ($table === '' || $properties === [] || !in_array($key, $properties, true)
            || count(array_unique($properties)) !== count($properties)) {
            throw new \InvalidArgumentException('Mapping needs a table, unique properties and a mapped key.');
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException('Entity must be instantiable.');
        }
        foreach ($properties as $name) {
            if (!$reflection->hasProperty($name) && $reflection->isSubclassOf(Entity::class)) {
                continue;
            }
            $property = $reflection->getProperty($name);
            if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly()) {
                throw new \InvalidArgumentException('Mapped properties must be public, mutable instance properties.');
            }
        }
    }

    /** @param list<\PhpEntity\Schema\Column> $columns */
    public static function fromSchema(string $class, string $table, array $columns): self
    {
        $keys = array_values(array_filter($columns, fn ($c) => $c->primary));
        if (count($keys) !== 1) {
            throw new \LogicException("Exactly one primary key is required.");
        }
        return new self($class, $table, array_column($columns, "name"), $keys[0]->name, $keys[0]->generated, array_column($columns, null, "name"));
    }

    public function values(object $entity): array
    {
        if (!$entity instanceof $this->class) {
            throw new \InvalidArgumentException('Entity does not match mapping.');
        }
        $values = [];
        foreach ($this->properties as $property) {
            $value = $entity->$property ?? null;
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException('Only scalar and null values are supported.');
            }
            $values[$property] = $value;
        }
        return $values;
    }

    public function insertValues(object $entity): array
    {
        $values = $this->values($entity);
        foreach ($this->properties as $name) {
            $column = $this->columns[$name] ?? null;
            $reflection = new \ReflectionClass($entity);
            $initialized = $reflection->hasProperty($name)
                ? $reflection->getProperty($name)->isInitialized($entity)
                : ($entity instanceof Entity && $entity->hasValue($name));
            if (($name === $this->key && $this->generatedKey) || $column?->computed || !$initialized) {
                unset($values[$name]);
            }
        }
        return $values;
    }

    public function hydrate(array $row): object
    {
        $entity = (new \ReflectionClass($this->class))->newInstanceWithoutConstructor();
        foreach ($this->properties as $property) {
            $entity->$property = isset($this->columns[$property])
                ? $this->columns[$property]->convert($row[$property]) : $row[$property];
        }
        return $entity;
    }
}
