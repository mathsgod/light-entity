<?php

declare(strict_types=1);

namespace Light\Entity;

/** One required, single-level value object flattened into its owner's columns. */
final readonly class ComplexPropertyMapping
{
    /** @var array<string, string> Member name => database column. */
    public array $columns;
    private \ReflectionClass $reflection;

    public function __construct(public string $name, public string $class, array $columns = [])
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new \InvalidArgumentException('Complex properties require a simple property name.');
        }
        $this->reflection = new \ReflectionClass($class);
        if (!$this->reflection->isInstantiable() || is_a($class, Entity::class, true)) {
            throw new \InvalidArgumentException('A complex type must be an instantiable value object, not an Entity.');
        }
        $members = [];
        foreach ($this->reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $member) {
            if ($member->isStatic()) continue;
            if ($member->isReadOnly() || (method_exists($member, 'getHooks') && $member->getHooks() !== [])) {
                throw new \InvalidArgumentException('Complex members must be public mutable scalar properties without hooks.');
            }
            $type = $member->getType();
            $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : ($type === null ? [] : [$type]);
            foreach ($types as $part) {
                if (!$part instanceof \ReflectionNamedType || !in_array($part->getName(), ['int', 'float', 'string', 'bool', 'null'], true)) {
                    throw new \InvalidArgumentException('Nested objects and collections are not supported in complex properties.');
                }
            }
            $column = array_key_exists($member->getName(), $columns) ? $columns[$member->getName()] : $name.'_'.$member->getName();
            if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) {
                throw new \InvalidArgumentException('Complex members require unqualified column names.');
            }
            $members[$member->getName()] = $column;
        }
        if ($members === [] || array_diff_key($columns, $members) !== [] || count(array_unique($members)) !== count($members)) {
            throw new \InvalidArgumentException('Complex column mappings require known members and unique columns.');
        }
        $this->columns = $members;
    }

    /** @internal */
    public function validateOwner(\ReflectionClass $owner): void
    {
        if (!$owner->hasProperty($this->name)) {
            if (!$owner->isSubclassOf(Entity::class)) throw new \InvalidArgumentException('Declare the complex property on the owning class.');
            return;
        }
        $property = $owner->getProperty($this->name);
        $type = $property->getType();
        if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly()
            || (method_exists($property, 'getHooks') && $property->getHooks() !== [])
            || ($type !== null && (!$type instanceof \ReflectionNamedType || $type->allowsNull() || $type->isBuiltin() || !is_a($this->class, $type->getName(), true)))) {
            throw new \InvalidArgumentException('Complex properties must be public mutable required objects of the configured type.');
        }
    }

    /** @internal */
    public function object(object $owner): object
    {
        $value = $owner->{$this->name} ?? null;
        if (!$value instanceof $this->class) {
            throw new \InvalidArgumentException('Complex property '.$this->name.' requires an instance of '.$this->class.'.');
        }
        return $value;
    }

    /** @internal */
    public function values(object $owner): array
    {
        $value = $this->object($owner);
        $values = [];
        foreach ($this->columns as $member => $column) {
            $item = $value->$member ?? null;
            if ($item !== null && !is_scalar($item)) throw new \InvalidArgumentException('Complex members must contain scalar or null values.');
            $values[$column] = $item;
        }
        return $values;
    }

    /** @internal Apply flat model values, preserving an existing object's identity. */
    public function apply(object $owner, array $values): void
    {
        $value = $owner->{$this->name} ?? null;
        if (!$value instanceof $this->class) {
            $value = $this->reflection->newInstanceWithoutConstructor();
            $owner->{$this->name} = $value;
        }
        foreach ($this->columns as $member => $column) {
            if (!array_key_exists($column, $values)) continue;
            $item = $values[$column];
            if ($item !== null && !is_scalar($item)) throw new \InvalidArgumentException('Complex members must contain scalar or null values.');
            $value->$member = $item;
        }
    }

    /** @internal */
    public function initialized(object $owner, string $member): bool
    {
        return $this->reflection->getProperty($member)->isInitialized($this->object($owner));
    }
}
