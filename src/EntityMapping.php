<?php

declare(strict_types=1);

namespace Light\Entity;

/** Runtime metadata. Usually discovered from the database schema. */
final readonly class EntityMapping
{
    private \ReflectionClass $reflection;
    private bool $directValuesCompatible;
    private array $propertyKeys;
    /** @internal Eligibility for native bulk hydration (legacy property name). */
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
        $this->propertyKeys = array_fill_keys($properties, true);
        $this->directValuesCompatible = $reflection->isSubclassOf(Entity::class)
            && $reflection->getMethod('__get')->getDeclaringClass()->getName() === Entity::class
            && $reflection->getMethod('__set')->getDeclaringClass()->getName() === Entity::class
            && $reflection->getMethod('__isset')->getDeclaringClass()->getName() === Entity::class
            && array_filter($properties, fn ($name) => $reflection->hasProperty($name)
                && $reflection->getProperty($name)->isPublic()) === [];
        $this->pdoFetchClassCompatible = $reflection->isSubclassOf(Entity::class)
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
        if (!$entity instanceof $this->class) { throw new \InvalidArgumentException('Entity does not match mapping.'); }
        if ($this->directValuesCompatible && $entity instanceof Entity) {
            $values = $entity->storedValues();
            if (array_keys($values) !== $this->properties) {
                $values = array_replace(array_fill_keys($this->properties, null), array_intersect_key($values, $this->propertyKeys));
            }
        } else {
            $values = [];
            foreach ($this->properties as $property) { $values[$property] = $entity->$property ?? null; }
        }
        $this->validateModelValues($values);
        return $values;
    }

    private function validateModelValues(array $values): void
    {
        foreach ($values as $property => $value) {
            if ($value !== null && !is_scalar($value) && !isset($this->converters[$property], $this->comparers[$property])) {
                throw new \InvalidArgumentException('Non-scalar model values require a ValueConverter and ValueComparer.');
            }
        }
    }

    /** Apply model values, not database values. Typed assignments use normal PHP semantics. */
    public function applyValues(object $entity, array $values): void
    {
        if (!$entity instanceof $this->class) { throw new \InvalidArgumentException('Entity does not match mapping.'); }
        if (array_diff_key($values, $this->propertyKeys) !== []) { throw new \InvalidArgumentException('Values contain unknown mapped properties.'); }
        foreach ($values as $property => $value) { $entity->$property = $value; }
    }

    /** Build a model-value snapshot without duplicating storage for ordinary dynamic entities. */
    public function snapshotEntity(object $entity, bool $fromProvider = false): array
    {
        return $this->snapshotValues($this->values($entity), $fromProvider && $this->directValuesCompatible);
    }

    /** Normalize and validate a complete original-value dictionary before tracking it. */
    public function validateOriginalValues(array $values): array
    {
        if (count($values) !== count($this->properties) || array_diff_key($values, $this->propertyKeys) !== []) {
            throw new \InvalidArgumentException('Original values must contain exactly all mapped properties.');
        }
        $values = array_replace(array_fill_keys($this->properties, null), $values);
        $this->validateModelValues($values);
        $this->keyValues($values);
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
    public function convertPredicate(\Light\Linq\Expressions\ValueExpression $predicate): \Light\Linq\Expressions\ValueExpression
    {
        if ($predicate instanceof \Light\Linq\Expressions\BinaryExpression) {
            // Arithmetic constants are operands, not model values to convert.
            if (in_array($predicate->operator, ['+', '-', '*', '/', '%'], true)) {
                return $predicate;
            }
            $left = $predicate->left;
            $right = $predicate->right;
            if ($left instanceof \Light\Linq\Expressions\FieldExpression && $right instanceof \Light\Linq\Expressions\ConstantExpression
                && isset($this->converters[$left->path])) {
                $right = new \Light\Linq\Expressions\ConstantExpression($this->toProviderValue($left->path, $right->value));
            } elseif ($right instanceof \Light\Linq\Expressions\FieldExpression && $left instanceof \Light\Linq\Expressions\ConstantExpression
                && isset($this->converters[$right->path])) {
                $left = new \Light\Linq\Expressions\ConstantExpression($this->toProviderValue($right->path, $left->value));
            }
            return new \Light\Linq\Expressions\BinaryExpression($left, $predicate->operator, $right);
        }
        if ($predicate instanceof \Light\Linq\Expressions\LogicalExpression) {
            return new \Light\Linq\Expressions\LogicalExpression($this->convertPredicate($predicate->left), $predicate->operator, $this->convertPredicate($predicate->right));
        }
        if ($predicate instanceof \Light\Entity\Sql\NotExpression || $predicate instanceof \Light\Linq\Expressions\NotExpression) {
            $class = $predicate::class;
            return new $class($this->convertPredicate($predicate->operand));
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

    public function snapshotValues(array $values, bool $fromProvider = false): array
    {
        foreach ($this->comparers as $name => $comparer) {
            if (!array_key_exists($name, $values)) { continue; }
            $value = $values[$name];
            $snapshot = $fromProvider ? $comparer->snapshotFromProvider($value) : $comparer->snapshot($value);
            // Preserve the shared outer array when the provider snapshot is unchanged.
            if (!$fromProvider || $snapshot !== $value) { $values[$name] = $snapshot; }
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
