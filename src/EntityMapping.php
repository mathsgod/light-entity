<?php

declare(strict_types=1);

namespace Light\Entity;

/** Runtime metadata. Usually discovered from the database schema. */
final readonly class EntityMapping
{
    private \ReflectionClass $reflection;
    /** @internal Eligibility for native bulk hydration; also compatible with FETCH_CLASS. */
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
     * @param array<string, ValueConverter> $converters
     * @param array<string, ValueComparer> $comparers
     */
    public function __construct(
        public string $class,
        public string $table,
        public array $properties,
        public string|array $key = 'id',
        public bool $generatedKey = true,
        public array $columns = [],
        public array $converters = [],
        public array $comparers = [],
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
        foreach (['converters' => ValueConverter::class, 'comparers' => ValueComparer::class] as $group => $type) {
            foreach ($this->$group as $name => $handler) {
                if (!in_array($name, $properties, true) || !$handler instanceof $type) {
                    throw new \InvalidArgumentException("Invalid {$group} entry for property: {$name}");
                }
                if (in_array($name, $this->keys, true)) {
                    throw new \InvalidArgumentException('Primary-key converters and comparers are not supported.');
                }
            }
        }
        $reflection = $this->reflection = new \ReflectionClass($class);
        $this->pdoFetchClassCompatible = $converters === [] && $reflection->isSubclassOf(Entity::class)
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

    /** @param list<\Light\Entity\Schema\Column> $columns */
    public static function fromSchema(string $class, string $table, array $columns, array $converters = [], array $comparers = []): self
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
            $converters, $comparers,
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
                if (!isset($this->converters[$property], $this->comparers[$property])) {
                    throw new \InvalidArgumentException('Non-scalar model values require a ValueConverter and ValueComparer.');
                }
            }
            $values[$property] = $value;
        }
        return $values;
    }

    /** Converts a model-value dictionary for SQL binding. */
    public function toProviderValues(array $values): array
    {
        foreach ($values as $name => $value) {
            $values[$name] = $this->toProviderValue($name, $value);
        }
        return $values;
    }

    public function toProviderValue(string $property, mixed $value): mixed
    {
        $value = isset($this->converters[$property]) ? $this->converters[$property]->convertToProvider($value) : $value;
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Database values must be scalar or null.');
        }
        return $value;
    }

    /** Convert direct field/constant comparisons to provider values. */
    public function convertPredicate(\Light\Linq\Expression\ValueExpression $predicate): \Light\Linq\Expression\ValueExpression
    {
        if ($predicate instanceof \Light\Linq\Expression\BinaryExpression) {
            $left = $predicate->left;
            $right = $predicate->right;
            if ($left instanceof \Light\Linq\Expression\FieldExpression && $right instanceof \Light\Linq\Expression\ConstantExpression
                && isset($this->converters[$left->path])) {
                $right = new \Light\Linq\Expression\ConstantExpression($this->toProviderValue($left->path, $right->value));
            } elseif ($right instanceof \Light\Linq\Expression\FieldExpression && $left instanceof \Light\Linq\Expression\ConstantExpression
                && isset($this->converters[$right->path])) {
                $left = new \Light\Linq\Expression\ConstantExpression($this->toProviderValue($right->path, $left->value));
            }
            return new \Light\Linq\Expression\BinaryExpression($left, $predicate->operator, $right);
        }
        if ($predicate instanceof \Light\Linq\Expression\LogicalExpression) {
            return new \Light\Linq\Expression\LogicalExpression($this->convertPredicate($predicate->left), $predicate->operator, $this->convertPredicate($predicate->right));
        }
        if ($predicate instanceof \Light\Entity\Sql\NotExpression) {
            return new \Light\Entity\Sql\NotExpression($this->convertPredicate($predicate->operand));
        }
        return $predicate;
    }

    /** Translate model values before WhereFilter validates scalar SQL operands. */
    public function convertFilter(array $filter): array
    {
        foreach ($filter as $name => $condition) {
            if (in_array($name, ['_and', '_or'], true) && is_array($condition)) {
                $filter[$name] = array_map(fn ($child) => is_array($child) ? $this->convertFilter($child) : $child, $condition);
            } elseif ($name === '_not' && is_array($condition)) {
                $filter[$name] = $this->convertFilter($condition);
            } elseif (isset($this->converters[$name])) {
                if (!is_array($condition)) {
                    $filter[$name] = $this->toProviderValue($name, $condition);
                } else {
                    foreach ($condition as $operator => $value) {
                        $filter[$name][$operator] = $operator === 'in' && is_array($value)
                            ? array_map(fn ($item) => $this->toProviderValue($name, $item), $value)
                            : $this->toProviderValue($name, $value);
                    }
                }
            }
        }
        return $filter;
    }

    public function snapshotValues(array $values): array
    {
        foreach ($values as $name => $value) {
            if (isset($this->comparers[$name])) { $values[$name] = $this->comparers[$name]->snapshot($value); }
        }
        return $values;
    }

    public function propertyEquals(string $name, mixed $left, mixed $right): bool
    {
        return isset($this->comparers[$name]) ? $this->comparers[$name]->equals($left, $right) : $left === $right;
    }

    public function valuesEqual(array $left, array $right): bool
    {
        if (array_keys($left) !== array_keys($right)) { return false; }
        foreach ($left as $name => $value) {
            if (!$this->propertyEquals($name, $value, $right[$name])) { return false; }
        }
        return true;
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
            // A configured converter receives the raw provider value, before schema coercion.
            $entity->$property = isset($this->converters[$property])
                ? $this->converters[$property]->convertFromProvider($row[$property])
                : (isset($this->columns[$property]) ? $this->columns[$property]->convert($row[$property]) : $row[$property]);
        }
        return $entity;
    }
}
