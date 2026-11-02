<?php

declare(strict_types=1);

namespace PhpEntity;

/** Runtime metadata. Usually discovered from the database schema. */
final readonly class EntityMapping
{
    private \ReflectionClass $reflection;
    /** @internal Only schema-driven entities with no constructor or custom setters use FETCH_CLASS. */
    public bool $pdoFetchClassCompatible;
    /** @var list<string> @internal */
    public array $booleanProperties;

    /** @var non-empty-list<string> */
    public array $keys;
    public ?string $generatedKeyProperty;

    /**
     * @param class-string $class
     * @param non-empty-list<string> $properties
     * @param string|non-empty-list<string> $key
     */
    public function __construct(
        public string $class,
        public string $table,
        public array $properties,
        public string|array $key = 'id',
        public bool $generatedKey = true,
        public array $columns = [],
    ) {
        $this->keys = is_array($key) ? array_values($key) : [$key];
        if ($this->keys === []
            || array_filter($this->keys, fn ($name) => !is_string($name) || !in_array($name, $properties, true))
            || count(array_unique($this->keys)) !== count($this->keys)) {
            throw new \InvalidArgumentException('Mapping requires unique mapped primary-key columns.');
        }
        $generated = array_values(array_filter($this->keys, fn ($name) => ($columns[$name] ?? null)?->generated));
        if (count($generated) > 1 || ($generatedKey && count($this->keys) > 1 && count($generated) !== 1)) {
            throw new \InvalidArgumentException('Specify exactly one generated primary-key column in column metadata, or disable generatedKey.');
        }
        $this->generatedKeyProperty = $generatedKey ? ($generated[0] ?? $this->keys[0]) : null;
        if ($table === '' || $properties === []
            || count(array_unique($properties)) !== count($properties)) {
            throw new \InvalidArgumentException('Mapping needs a table, unique properties and a mapped key.');
        }
        $reflection = $this->reflection = new \ReflectionClass($class);
        $this->pdoFetchClassCompatible = $reflection->isSubclassOf(Entity::class)
            && $reflection->getConstructor() === null
            && $reflection->getMethod('__set')->getDeclaringClass()->getName() === Entity::class
            && array_filter($properties, fn ($name) => $reflection->hasProperty($name)) === []
            && array_filter($properties, fn ($name) => !isset($columns[$name])
                || preg_match('/^(bit|year)\b/i', $columns[$name]->type)) === [];
        $this->booleanProperties = array_values(array_filter($properties, fn ($name) => isset($columns[$name])
            && ($columns[$name]->boolean || preg_match('/\b(bool|boolean)\b/i', $columns[$name]->type))));
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
        if ($keys === []) {
            throw new \LogicException("At least one primary key is required.");
        }
        return new self(
            $class, $table, array_column($columns, 'name'),
            count($keys) === 1 ? $keys[0]->name : array_column($keys, 'name'),
            array_filter($keys, fn ($c) => $c->generated) !== [],
            array_column($columns, null, 'name'),
        );
    }

    /** Extract a complete key in mapping order. */
    public function keyValues(array $values): array
    {
        $key = [];
        foreach ($this->keys as $name) {
            $value = $values[$name] ?? null;
            if ($value === null || !is_scalar($value)) {
                throw new \LogicException("Missing or invalid primary key: {$name}");
            }
            $key[$name] = $value;
        }
        return $key;
    }

    /** find() accepts a scalar for a single key or an associative key array. */
    public function normalizeKey(int|string|array $key): array
    {
        if (!is_array($key)) {
            if (count($this->keys) !== 1) {
                throw new \InvalidArgumentException('Composite keys require an associative array.');
            }
            $key = [$this->keys[0] => $key];
        }
        if (count($key) !== count($this->keys) || array_diff($this->keys, array_keys($key))) {
            throw new \InvalidArgumentException('Provide exactly the mapped primary-key columns.');
        }
        return $this->keyValues($key);
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
            if ($name === $this->generatedKeyProperty || $column?->computed || !$initialized) {
                unset($values[$name]);
            }
        }
        return $values;
    }

    /** Entity queries require every mapped column, even when identity tracking reuses an object. */
    public function validateRow(array $row): void
    {
        foreach ($this->properties as $property) {
            if (!array_key_exists($property, $row)) {
                throw new \LogicException("Query result is missing mapped column: {$property}");
            }
        }
    }

    public function hydrate(array $row): object
    {
        $this->validateRow($row);
        $entity = $this->reflection->newInstanceWithoutConstructor();
        foreach ($this->properties as $property) {
            $entity->$property = isset($this->columns[$property])
                ? $this->columns[$property]->convert($row[$property]) : $row[$property];
        }
        return $entity;
    }
}
